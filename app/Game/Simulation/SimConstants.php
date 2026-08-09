<?php

namespace App\Game\Simulation;

// 模拟系统常量:地图尺寸、初始人口/资源区间、容量产出类型等
class SimConstants
{
    // 人均粮食消耗(每分钟)
    public const FOOD_PER_CAPITA_PER_MIN = 0.1;

    // 基础仓储容量(无仓储类建筑时的默认上限)
    // 注意:必须大于 START_RESOURCES 各资源上限(木材400/粮食500),
    // 否则新城建成时资源已超过上限,首次结算会被夹到 200 而丢失资源(见 P4 Task3 调试记录)
    public const BASE_STORAGE = 1000;

    // 地图宽高(格)
    public const MAP_W = 20;
    public const MAP_H = 20;

    // 建城初始人口
    public const START_POPULATION = 10;

    // 容量类产出(建筑等级定义中的产出类型)
    public const CAPACITY_OUTPUTS = [
        '人口容量', '仓储容量', '治理容量', '运输容量', '国防值', '贸易容量', '金融容量', '医疗容量',
    ];

    // 建城初始资源区间 [下限, 上限],区间内随机取整数
    public const START_RESOURCES = [
        '木材' => [200, 400],
        '石料' => [100, 200],
        '粮食' => [300, 500],
    ];

    // 建城初始资金区间 [下限, 上限]
    public const START_MONEY = [200, 400];
}
