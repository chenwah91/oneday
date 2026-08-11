<?php

namespace Tests\Feature\Event;

use App\Game\City\CityFactory;
use App\Game\Event\EventDefinition;
use App\Game\Event\EventRandom;
use App\Game\Event\EventRuntimeService;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\User;
use App\Support\GameSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// M3-D4 事件测试的共同脚手架。
//
// 时间一律冻结在固定基准时刻:事件的掷点种子是 (city_id, window_index),
// 而 window_index = floor(时间戳 / 窗长) —— 时间不冻结,窗口号就会漂,断言也就不可复现。
abstract class EventTestCase extends TestCase
{
    // 固定基准时刻(选在整分钟边界上,窗口号才是整数)
    protected const BASE = '2026-01-01 00:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow(Carbon::parse(self::BASE));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        EventRandom::resetWarningState();
        parent::tearDown();
    }

    // 建一座测试城:清掉建城送的建筑,资金 / 人口 / 时代按需要覆盖
    protected function makeCity(string $un, array $overrides = []): array
    {
        $user = User::create(['username' => $un, 'name' => $un, 'email' => "{$un}@example.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($user);

        DB::table('city_building_instances')->where('city_id', $city->id)->delete();
        DB::table('cities')->where('id', $city->id)->update(array_merge([
            'money'             => 100000,
            'population'        => 300,
            'era_order'         => 3,
            'happiness'         => 60,
            'last_simulated_at' => self::BASE,
            'event_settled_at'  => self::BASE,
        ], $overrides));

        return [$city->fresh(), $user];
    }

    protected function addBuilding(City $city, string $buildingId, int $workers = 0, string $status = 'active', int $level = 1): int
    {
        static $x = 0;
        $x = ($x + 2) % 40;

        return (int) CityBuildingInstance::create([
            'city_id' => $city->id, 'building_id' => $buildingId, 'level' => $level,
            'x' => $x, 'y' => 3, 'status' => $status, 'assigned_workers' => $workers,
        ])->id;
    }

    protected function setResource(City $city, string $code, float $amount): void
    {
        DB::table('city_resources')->updateOrInsert(
            ['city_id' => $city->id, 'resource_id' => $code],
            ['amount' => $amount]
        );
    }

    // 只保留给定事件为启用状态(其余全部停用),并把触发概率拉满。
    // 掷点本身仍然走 EventRandom 的确定性派生 —— 概率 1.0 只是让「要不要触发」这一掷必中,
    // 「抽中谁」仍由权重决定(单候选时结果唯一)
    protected function onlyEnable(string ...$eventIds): void
    {
        DB::table('event_definition')->update(['enabled' => false]);
        DB::table('event_definition')->whereIn('event_id', $eventIds)->update(['enabled' => true]);
        EventDefinition::flush();
        GameSetting::set(GameSetting::EVENT_TRIGGER_CHANCE, 1.0, null, 'test');
    }

    // 把时钟推进 $minutes 分钟,跑一次「结算 + 事件懒结算」(= 玩家拉一次快照)
    protected function runSettle(City $city, int $minutes): array
    {
        Carbon::setTestNow(Carbon::parse(self::BASE)->addMinutes($minutes));

        $fresh = $city->fresh();
        $sim = SimulationService::simulate($fresh);
        EventRuntimeService::settle($fresh, $sim);

        return $sim;
    }

    protected function activeInstances(City $city)
    {
        return DB::table('city_events')->where('city_id', $city->id)
            ->where('status', 'active')->orderBy('id')->get();
    }

    protected function resourceOf(City $city, string $code): float
    {
        return (float) DB::table('city_resources')->where('city_id', $city->id)
            ->where('resource_id', $code)->value('amount');
    }
}
