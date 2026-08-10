<?php

namespace App\Game\Simulation;

use App\Game\Resource\ResourceCode;

// 模拟系统常量:地图尺寸、初始人口/资源区间、容量产出类型等
class SimConstants
{
    // 人均粮食消耗(每分钟)
    // 依据 v3.1 §10.1「基础粮食消耗/分钟 = population × 0.03」;
    // 此前实现写的是 0.1(偏离规格 3.3 倍),M2 资源 code 迁移时一并修正
    public const FOOD_PER_CAPITA_PER_MIN = 0.03;

    // 最大离线结算时长(秒):超过此时长的离线段按此上限结算,防止长期挂机一次性暴收
    // 依据 CLAUDE §18 参考值 12h/24h 取 12h,最终数值待用户确认,可调
    public const MAX_OFFLINE_SECONDS = 43200;

    // 基础仓储容量(无仓储类建筑时的默认上限)
    // 注意:必须大于 START_RESOURCES 各资源上限(wood 400/food 500),
    // 否则新城建成时资源已超过上限,首次结算会被夹到 200 而丢失资源(见 P4 Task3 调试记录)
    public const BASE_STORAGE = 1000;

    // 地图宽高(格)
    public const MAP_W = 20;
    public const MAP_H = 20;

    // 建城初始人口
    // v3.2 §10.4「M2 接入劳动力系统时的存档兼容:现有新城/初始城人口 10 → 30」
    public const START_POPULATION = 30;

    // 劳动力比例:availableWorkers = floor(population × 0.60)(v3.2 §10.4)
    public const WORKER_RATIO = 0.60;

    // 人口基础增长率(每分钟):所有因子为 1.0 时的上限基准 0.2%/min(v3.2 §10.3)
    public const BASE_GROWTH_PER_MIN = 0.002;

    // 分段结算的段长(分钟):离线时长按此切段,段内人口恒定、段末更新人口(CLAUDE §18 分段结算)
    // 12h 封顶 ÷ 30min = 24 段,恰好等于 MAX_SEGMENTS
    public const SEGMENT_MINUTES = 30;

    // 单次结算的最大段数:12h/30min = 24。段数上限保证最坏情况下的循环次数可控
    public const MAX_SEGMENTS = 24;

    // ---- 粮食赤字三级后果(v3.2 §10.1,数值不得在代码里改,要调先提 game_data_version)----

    // 粮食库存低于「几分钟的当前人口消耗」判定为严重短缺(§10.1「粮食库存 < 3 分钟当前人口消耗」)
    public const FOOD_SHORTAGE_MINUTES = 3;

    // 严重短缺:population -0.5%/分钟(迁出)
    public const FOOD_SHORTAGE_LOSS_PER_MIN = -0.005;

    // 粮食库存归零后的宽限时长(分钟):持续归零 >= 10 分钟才开始按饥荒扣人口
    public const FOOD_ZERO_GRACE_MINUTES = 10;

    // 饥荒:population -1.0%/分钟
    public const FOOD_ZERO_LOSS_PER_MIN = -0.01;

    // 人口下限:人口短缺损失不能使人口低于 5(§10.1;只约束损失方向)
    public const MIN_POPULATION = 5;

    // 住房因子分段函数的两个拐点(§10.3 housingFactor):
    // 使用率 < 0.80 → 1.0;0.80~1.00 → 从 1.0 线性下降到 0.2;>= 1.00 → 0
    public const HOUSING_USAGE_FULL = 0.80;
    public const HOUSING_FACTOR_AT_CAP = 0.2;

    // 容量类产出(建筑等级定义中的产出类型)
    // 单一来源是 ResourceCode::CAPACITY,这里只做别名,避免调用方两处引用不一致
    public const CAPACITY_OUTPUTS = ResourceCode::CAPACITY;

    // 建城初始资源区间 [下限, 上限],区间内随机取整数
    public const START_RESOURCES = [
        ResourceCode::WOOD  => [200, 400],  // 木材
        ResourceCode::STONE => [100, 200],  // 石料
        ResourceCode::FOOD  => [300, 500],  // 粮食
    ];

    // 建城初始资金区间 [下限, 上限]
    public const START_MONEY = [200, 400];
}
