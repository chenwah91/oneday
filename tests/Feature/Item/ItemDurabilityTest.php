<?php

namespace Tests\Feature\Item;

use App\Game\City\CityFactory;
use App\Game\Item\ItemCode;
use App\Game\Item\ItemRuntimeService;
use App\Game\Simulation\SimConstants;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\User;
use App\Support\GameSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 耐久懒结算(v3.2 §7 + backlog §4.3 / §9 B1 / B4):
// 精确基线 / 两个档位 / 四道「不工作就不扣」的闸 / 归零损毁 / 离线封顶 / 设定改动生效。
class ItemDurabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    // §7:普通工具每 10 分钟工作消耗 1 点 → 工作 30 分钟正好扣 3 点
    public function test_normal_tier_consumes_one_point_per_ten_working_minutes(): void
    {
        [$city, $instanceId] = $this->workingCity();
        $itemId = $this->equip($city, 'IT003', $instanceId);   // 耐久 80,normal 档

        $this->elapse($city, 30);

        $this->assertEqualsWithDelta(77.0, $this->durability($itemId), 0.001);
    }

    // §7 + B1:工业/电子设备每 20 分钟 1 点 → 30 分钟扣 1.5 点(耐久按 DECIMAL 存,小数不丢)
    public function test_industrial_tier_consumes_one_point_per_twenty_working_minutes(): void
    {
        [$city, $instanceId] = $this->workingCity();
        $itemId = $this->equip($city, 'IT021', $instanceId);   // 耐久 250,industrial 档

        $this->elapse($city, 30);

        $this->assertEqualsWithDelta(248.5, $this->durability($itemId), 0.001);
    }

    // 闸①:施工 / 升级中的楼不生产 → 一点都不扣
    public function test_no_consumption_when_building_is_not_active(): void
    {
        [$city, $instanceId] = $this->workingCity();
        $itemId = $this->equip($city, 'IT003', $instanceId);
        DB::table('city_building_instances')->where('id', $instanceId)
            ->update(['status' => 'upgrading', 'construction_finished_at' => now()->addHour()]);

        $this->elapse($city, 60);

        $this->assertEqualsWithDelta(80.0, $this->durability($itemId), 0.001);
    }

    // 闸②:用工闸门 —— 需要工人却一个都没派 = 没在工作(§10.4 workerFactor = 0)
    public function test_no_consumption_when_no_workers_assigned(): void
    {
        [$city, $instanceId] = $this->workingCity(workers: 0);
        $itemId = $this->equip($city, 'IT003', $instanceId);

        $this->elapse($city, 60);

        $this->assertEqualsWithDelta(80.0, $this->durability($itemId), 0.001);
    }

    // 闸③:欠费半停工 → 本次结算一点都不扣
    public function test_no_consumption_when_maintenance_is_in_arrears(): void
    {
        [$city, $instanceId] = $this->workingCity();
        $itemId = $this->equip($city, 'IT003', $instanceId);

        $this->elapse($city, 60, ['maintenanceArrears' => true]);

        $this->assertEqualsWithDelta(80.0, $this->durability($itemId), 0.001);
    }

    // 闸④:缺料 —— 配方里的原料库存为 0,这一段没料可吃
    public function test_no_consumption_when_recipe_input_is_missing(): void
    {
        // P01 磨坊吃 food 出 flour
        [$city, $instanceId] = $this->workingCity(buildingId: 'P01');
        $itemId = $this->equip($city, 'IT004', $instanceId);

        $this->elapse($city, 60, ['resources' => ['food' => 0]]);
        $this->assertEqualsWithDelta(100.0, $this->durability($itemId), 0.001, '缺料的建筑不该扣耐久');

        // 有料就照扣(同一栋楼、同一段时长,只有库存不同)
        $this->elapse($city, 60, ['resources' => ['food' => 500]]);
        $this->assertEqualsWithDelta(94.0, $this->durability($itemId), 0.001);
    }

    // B4 已批:耐久归零 = 损毁消失 + 自动卸下 + 写 ITEM.BROKEN 审计
    public function test_zero_durability_breaks_and_unequips_the_item(): void
    {
        [$city, $instanceId] = $this->workingCity();
        $itemId = $this->equip($city, 'IT003', $instanceId);
        DB::table('city_items')->where('id', $itemId)->update(['durability_left' => 1]);

        $this->elapse($city, 30);   // 需要扣 3 点,只剩 1 点

        $row = DB::table('city_items')->where('id', $itemId)->first();
        $this->assertSame(ItemCode::STATUS_BROKEN, $row->status);
        $this->assertNull($row->equipped_instance_id);
        $this->assertEqualsWithDelta(0.0, (float) $row->durability_left, 0.001);

        $audit = DB::table('audit_logs')->where('action', 'ITEM.BROKEN')->latest('id')->first();
        $this->assertNotNull($audit, '损毁必须留审计:玩家要能查出「工具是被用坏了,不是凭空消失」');
        $this->assertSame('system', $audit->actor_type);
        $this->assertSame('DURABILITY_EXHAUSTED', $audit->reason_code);
    }

    // B1 第三条:medical_item 是按使用次数的一次性消耗品,**不随时间递减**
    public function test_uses_mode_items_never_decay_over_time(): void
    {
        [$city, $instanceId] = $this->workingCity();
        $itemId = $this->equip($city, 'IT012', $instanceId);   // medical_item,durability 20 = 使用次数

        $this->elapse($city, 600);

        $this->assertEqualsWithDelta(20.0, $this->durability($itemId), 0.001);
    }

    // 运营救急开关:关掉耐久后不再递减(时钟照常推进,开回来不会突然补扣一大段)
    public function test_durability_switch_stops_consumption(): void
    {
        [$city, $instanceId] = $this->workingCity();
        $itemId = $this->equip($city, 'IT003', $instanceId);

        GameSetting::set(GameSetting::ITEM_DURABILITY_ENABLED, false, null, '测试关耐久');
        GameSetting::flush();

        $this->elapse($city, 60);
        $this->assertEqualsWithDelta(80.0, $this->durability($itemId), 0.001);

        // 开回来之后只扣「开回来之后」这一段,不补扣关闭期间的 60 分钟
        GameSetting::set(GameSetting::ITEM_DURABILITY_ENABLED, true, null, '测试开耐久');
        GameSetting::flush();

        $this->elapse($city, 10);
        $this->assertEqualsWithDelta(79.0, $this->durability($itemId), 0.001);
    }

    // 后台把「每点分钟数」调小 → 立刻更费工具(设定改动生效)
    public function test_minutes_per_point_setting_takes_effect(): void
    {
        [$city, $instanceId] = $this->workingCity();
        $itemId = $this->equip($city, 'IT003', $instanceId);

        GameSetting::set(GameSetting::ITEM_DURABILITY_MINUTES_NORMAL, 5, null, '测试加速磨损');
        GameSetting::flush();

        $this->elapse($city, 30);   // 5 分钟 1 点 → 6 点

        $this->assertEqualsWithDelta(74.0, $this->durability($itemId), 0.001);
    }

    // 离线封顶:挂机 48 小时上线,只按 MAX_OFFLINE_SECONDS(12h)扣,不是 48h
    public function test_offline_consumption_is_capped(): void
    {
        [$city, $instanceId] = $this->workingCity();
        $itemId = $this->equip($city, 'IT021', $instanceId);   // industrial 档,20 分钟 1 点

        $this->elapse($city, 48 * 60);

        // 12h = 720 分钟 → 36 点(而不是 48h 的 144 点)
        $expected = 250.0 - (SimConstants::MAX_OFFLINE_SECONDS / 60.0) / 20.0;
        $this->assertEqualsWithDelta($expected, $this->durability($itemId), 0.001);
    }

    // 时钟必须独立:结算完 item_settled_at 推进到当前时刻,同一段时间不会被扣第二遍
    public function test_settlement_clock_is_not_double_counted(): void
    {
        [$city, $instanceId] = $this->workingCity();
        $itemId = $this->equip($city, 'IT003', $instanceId);

        $this->elapse($city, 30);
        $this->assertEqualsWithDelta(77.0, $this->durability($itemId), 0.001);

        // 不再回拨时钟,直接再结一次:经过时间为 0,耐久不动
        ItemRuntimeService::settle($city->fresh(), $this->sim());
        $this->assertEqualsWithDelta(77.0, $this->durability($itemId), 0.001);
    }

    // ---- 夹具 ----

    // 一座「正在工作」的城市:一栋 active 建筑 + 已派工人 + 耐久时钟已初始化
    private function workingCity(string $buildingId = 'F02', int $workers = 6): array
    {
        static $seq = 0;
        $seq++;
        $un = 'dur' . $seq;

        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = City::find(CityFactory::createForUser($u)->id);
        DB::table('cities')->where('id', $city->id)->update([
            'era_order' => 10, 'money' => 1000000, 'item_settled_at' => now(),
        ]);
        DB::table('city_building_instances')->where('city_id', $city->id)->delete();

        $instanceId = (int) CityBuildingInstance::create([
            'city_id' => $city->id, 'building_id' => $buildingId, 'level' => 1,
            'x' => 1, 'y' => 1, 'status' => 'active', 'assigned_workers' => $workers,
        ])->id;

        return [$city->fresh(), $instanceId];
    }

    private function equip(City $city, string $itemId, int $instanceId): int
    {
        $durability = DB::table('item_definition')->where('item_id', $itemId)->value('durability');

        return (int) DB::table('city_items')->insertGetId([
            'city_id' => $city->id, 'item_id' => $itemId,
            'durability_left' => $durability, 'status' => ItemCode::STATUS_EQUIPPED,
            'equipped_instance_id' => $instanceId, 'acquired_source' => ItemCode::SOURCE_CRAFT,
            'acquired_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // 把耐久时钟回拨 N 分钟后结算一次(= 模拟「过了 N 分钟」)。
    // 直接调服务层而不是打快照端点:耐久是分钟级的精确基线,
    // 走 HTTP 会把结算内核的人口/资源变化一起搅进来,断言就不再是「耐久扣了多少」
    private function elapse(City $city, int $minutes, array $sim = []): void
    {
        DB::table('cities')->where('id', $city->id)
            ->update(['item_settled_at' => now()->subMinutes($minutes)]);

        ItemRuntimeService::settle($city->fresh(), $this->sim($sim));
    }

    // 内核结算结果的替身:耐久只用到「欠费状态」与「结算后库存」两项
    private function sim(array $overrides = []): array
    {
        return array_merge([
            'maintenanceArrears' => false,
            'resources'          => ['food' => 1000, 'flour' => 1000, 'wood' => 1000],
        ], $overrides);
    }

    private function durability(int $cityItemId): float
    {
        return (float) DB::table('city_items')->where('id', $cityItemId)->value('durability_left');
    }
}
