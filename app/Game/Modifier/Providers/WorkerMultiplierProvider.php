<?php

namespace App\Game\Modifier\Providers;

use App\Game\Modifier\ModifierContext;
use App\Game\Modifier\ModifierTarget;
use App\Game\Modifier\MultiplierProvider;
use App\Support\GameSetting;

// worker 乘区(v3.2 §10.4,M2-C1 迁入):workerFactor = min(1, assignedWorkers / max(1, workerRequired))。
//
// 口径与重构前逐字一致:
//   - worker_required = 0 的建筑(住宅 / 仓库等)不需要人,恒 1.0;
//   - 派超了也不加成(min 夹住 1.0),§10.4 只有"不足打折"没有"超配加成";
//   - 用工闸门 game_settings.worker_gate_enabled 关闭时恒 1.0(运营救急,全服立刻恢复满额)。
//
// 闸门在 prepare() 里读一次:applyLocked 在事务内高频调用,逐实例读设置不可接受
// (GameSetting 本身带请求级缓存,提到准备段是第二道保险)。
final class WorkerMultiplierProvider extends MultiplierProvider
{
    private bool $gateEnabled = true;

    public function slot(): string
    {
        return ModifierTarget::SLOT_WORKER;
    }

    public function prepare(ModifierContext $context, array $units): void
    {
        $this->gateEnabled = (bool) GameSetting::get(GameSetting::WORKER_GATE_ENABLED, true);
    }

    public function multiplierFor(array $unit): float
    {
        $required = (int) ($unit['workerRequired'] ?? 0);

        if (! $this->gateEnabled || $required <= 0) {
            return 1.0;
        }

        return min(1.0, (int) ($unit['assignedWorkers'] ?? 0) / $required);
    }
}
