<?php

namespace App\Game\Modifier;

use Carbon\CarbonInterface;

// M3-D0.1:准备段上下文 —— Provider 取数时能拿到的全部输入。
//
// 纪律(与 SimulationService::applyLocked 的既有约定一致):
//   1. Provider 的 prepare() 在**锁内、分段循环之外**被调用一次,查库只能发生在那里;
//   2. multiplierFor() 是纯函数,循环内零查库(applyLocked 在事务里高频调用,
//      每实例查一次库是 M2 明令禁止的 N+1);
//   3. 本对象只读,Provider 不得回写。
//
// 为什么把「城市行」原样带上:各 Provider 需要的城市字段各不相同(电力要发电/耗电、
// 国防要 threat_level、事件要 last_simulated_at),逐个往构造函数加参数会让内核每接一个
// 系统就改一次签名 —— 而 D0 的全部意义就是让内核只改这一次。
final class ModifierContext
{
    // capacities 数组的键(全城容量类产出的聚合值,由内核在建实例中间结构时提取)
    public const CAP_STORAGE = 'storage';
    public const CAP_POPULATION = 'population';
    public const CAP_MEDICAL = 'medical';
    public const CAP_DEFENSE = 'defense';
    public const CAP_GOVERNANCE = 'governance';
    public const CAP_TRANSPORT = 'transport';
    // 电力装机容量(M.1):§8 RS017 的 trade_mode 是 capacity_contract —— 电力是产能不是库存,
    // 所以建筑 output_json 里的 electricity 与仓储 / 人口 / 治理 / 运输一样在内核提取成全城容量
    public const CAP_POWER = 'power';

    public function __construct(
        public readonly int $cityId,
        // 时代序号(cities.era_order);列缺失 / 为空时内核已按时代 I 兜底
        public readonly int $eraOrder,
        // 本次结算涉及的 building_id 去重列表(含 upgrading 实例的,与重构前 techMultipliers 的入参一致)
        public readonly array $buildingIds,
        // 全城容量类产出聚合值,键见上面的 CAP_*
        public readonly array $capacities,
        // 锁到的 cities 行(原样传入,不做任何加工)
        public readonly object $city,
        // 本次结算的终点时刻(= applyLocked 的 $now)
        public readonly CarbonInterface $now,
        // 本次结算窗口的总分钟数(离线封顶之后的口径)
        public readonly float $totalMinutes,
    ) {
    }

    // 读一项容量,缺键按 0.0(Fail Safe:少一项容量不该让内核炸)
    public function capacity(string $key): float
    {
        return (float) ($this->capacities[$key] ?? 0.0);
    }
}
