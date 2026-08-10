<?php

namespace App\Game\Modifier;

use App\Game\Modifier\Providers\EventMultiplierProvider;
use App\Game\Modifier\Providers\LogisticsMultiplierProvider;
use App\Game\Modifier\Providers\NpcMultiplierProvider;
use App\Game\Modifier\Providers\PowerMultiplierProvider;
use App\Game\Modifier\Providers\TechMultiplierProvider;
use App\Game\Modifier\Providers\ToolMultiplierProvider;
use App\Game\Modifier\Providers\WorkerMultiplierProvider;
use LogicException;

// M3-D0.1:modifier 总线(Provider 注册制)。
//
// 内核与各系统之间唯一的接口:SimulationService 只认这三个动作 ——
//   ModifierBus::default()      建总线(七个乘区各一个 Provider);
//   $bus->prepare($ctx, $units) 准备段一次性取数(锁内、分段循环外);
//   $bus->multipliersFor($unit) 逐实例拿回七格乘数。
//
// **M3 接一个新系统 = 在 default() 里换掉一行占位 Provider,内核一个字都不改。**
// 若某个新系统发现「没有槽装得下自己的效果」,正确做法是回 ModifierTarget 的
// CONSUMPTION_POINTS 登记一个非产量 target,而不是往七乘区加第八格(§10.11 名单固定)。
//
// §13 帽不在这里夹:乘积与封顶的唯一落点是 SimulationService::multiplierProduct(),
// 各 Provider 也不许在自己内部夹一次(M2 起的纪律「封顶只落在一处」)。
// 例外是 §6.4 的 NPC 双层帽(单建筑 1.60 / 全城 1.90)—— 那是 NPC 系统内部的合成规则,
// 在 NpcModifierProvider 内部夹完再交出一格,与 §13 的总帽不是同一件事。
final class ModifierBus
{
    // slot => ProviderInterface
    private array $providers = [];

    // target => flat 值合计(prepare 之后才有效)
    private array $flatTotals = [];

    private bool $prepared = false;

    // 默认总线:七个乘区各认领一个 Provider。
    //
    // 已接线三个(M2 迁入,行为与重构前逐槽一致):
    //   worker    §10.4 用工满足率
    //   logistics §10.7 运输负载
    //   tech      §5   同分支每解锁一条 +2%
    // 占位四个(恒 1.0,= 接入前的历史行为),各自等自己的波次:
    //   power  → M.1 电力(W4-A)   npc → D1(W2-A)
    //   tool   → D2 工具(W3-A)    event → D4 事件(W3-B)
    public static function default(): self
    {
        return (new self())
            ->register(new WorkerMultiplierProvider())
            ->register(new PowerMultiplierProvider())
            ->register(new LogisticsMultiplierProvider())
            ->register(new TechMultiplierProvider())
            ->register(new NpcMultiplierProvider())
            ->register(new ToolMultiplierProvider())
            ->register(new EventMultiplierProvider());
    }

    // 注册一个 Provider。槽名必须在 §10.11 的固定名单里,且一格只能有一个 Provider ——
    // 两个系统抢同一格时必须先把合成规则写清楚(如 NPC 的 §6.4 双层帽),而不是各写各的
    public function register(ProviderInterface $provider): self
    {
        $slot = $provider->slot();

        if (! ModifierTarget::isSlot($slot)) {
            throw new LogicException("Provider 认领了不存在的乘区槽:{$slot}");
        }
        if (isset($this->providers[$slot])) {
            throw new LogicException("乘区槽 {$slot} 已被 " . $this->providers[$slot]::class . ' 认领');
        }

        $this->providers[$slot] = $provider;

        return $this;
    }

    // 取某一格的 Provider(内核要从物流 Provider 拿 load / factor 等读数给前端显示)
    public function provider(string $slot): ProviderInterface
    {
        if (! isset($this->providers[$slot])) {
            throw new LogicException("乘区槽 {$slot} 没有 Provider");
        }

        return $this->providers[$slot];
    }

    // 准备段:逐 Provider 取数,并把 flat 通道的投稿汇总一次。
    // 调用点唯一 —— SimulationService::applyLocked 的分段循环之外(锁内)
    public function prepare(ModifierContext $context, array $units): void
    {
        // 七格必须全部有主:少一格就意味着某个实例会拿到不完整的乘区表,
        // 而 multiplierProduct() 对缺键是"静默按 1.0"的,不炸出来就会变成隐形的数值 bug
        foreach (ModifierTarget::SLOTS as $slot) {
            if (! isset($this->providers[$slot])) {
                throw new LogicException("乘区槽 {$slot} 没有 Provider,总线不完整");
            }
        }

        $this->flatTotals = [];

        foreach (ModifierTarget::SLOTS as $slot) {
            $this->providers[$slot]->prepare($context, $units);
        }

        // flat 通道汇总(D0.2)。M3 W1 没有任何 Provider 投稿 → 全部为 0.0
        foreach ($this->providers as $provider) {
            foreach ($provider->flatSpecs() as $spec) {
                if (! $spec instanceof ModifierSpec || $spec->op !== ModifierSpec::OP_FLAT) {
                    throw new LogicException('flatSpecs() 只接受 op=flat 的 ModifierSpec');
                }
                $this->flatTotals[$spec->target] = ($this->flatTotals[$spec->target] ?? 0.0) + $spec->value;
            }
        }

        $this->prepared = true;
    }

    // 逐实例的七格乘数,键顺序固定 = ModifierTarget::SLOTS。
    // 纯函数路径:这里以及每个 Provider 的 multiplierFor() 都不许查库
    public function multipliersFor(array $unit): array
    {
        $this->assertPrepared();

        $out = [];
        foreach (ModifierTarget::SLOTS as $slot) {
            $out[$slot] = (float) $this->providers[$slot]->multiplierFor($unit);
        }

        return $out;
    }

    // flat 通道取值(D0.2)。$fromOffset / $toOffset 是「相对结算窗口起点的分钟偏移」
    // (与 applyLocked 里 foodZeroOffset 等同一套口径),留给 D4 的持续型 modifier
    // 按段与 starts_at / ends_at 求交用 —— 先把参数留在签名里,免得 D4 回头改内核。
    // M3 W1 无投稿者,任何区间都返回 0.0 = 接入前的历史行为
    public function flat(string $target, ?float $fromOffset = null, ?float $toOffset = null): float
    {
        $this->assertPrepared();

        return (float) ($this->flatTotals[$target] ?? 0.0);
    }

    // 已注册的槽名(结构性测试用)
    public function slots(): array
    {
        return array_keys($this->providers);
    }

    private function assertPrepared(): void
    {
        if (! $this->prepared) {
            throw new LogicException('ModifierBus 必须先 prepare() 再取值');
        }
    }
}
