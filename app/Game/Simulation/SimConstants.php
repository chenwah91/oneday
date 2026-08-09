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
    public const START_POPULATION = 10;

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
