<?php

namespace App\Game\City;

use App\Game\Definition\GameDataVersion;
use App\Game\Resource\ResourceCode;
use App\Game\Simulation\SimConstants;
use App\Models\City;
use App\Models\CityResource;
use App\Models\User;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\GameSetting;
use Illuminate\Support\Facades\DB;

// 建城:事务内创建城市 + 初始资源(幂等)
//
// 初始资源的来源(用户 2026-08-10 拍板「测试阶段都送,后台可调」):
//   1. 首选 game_settings.initial_resources(对象型设定,{资源 code: 数量},含 money 与 knowledge);
//   2. 缺行 / 空对象 / 脏值 → 回退 SimConstants 的硬编码随机区间(即接入设定前的历史行为)。
// 读取走 GameSetting 的请求级缓存,一次注册只查一次库。
class CityFactory
{
    public static function createForUser(User $user): City
    {
        $existing = City::where('user_id', $user->id)->first();
        if ($existing) {
            return $existing;
        }

        // 事务外先取配置:它只读 game_settings,与建城事务无关,没必要占着行锁去查
        $config = self::initialResourceConfig();

        return DB::transaction(function () use ($user, $config) {
            $city = City::create([
                'user_id'           => $user->id,
                'name'              => $user->username . '的城市',
                'revision'          => 0,
                'last_simulated_at' => now(),
                'money'             => self::initialMoney($config),
                'population'        => SimConstants::START_POPULATION,
                'map_width'         => SimConstants::MAP_W,
                'map_height'        => SimConstants::MAP_H,
            ]);

            // 记下这座城「以哪一版数值开局」(§64):以后数值改版了,还能解释老城当初的开局资源是怎么来的。
            // game_data_version 不在 City::$fillable 里(它不是玩家/请求能决定的字段),所以用 forceFill 显式写入。
            $city->forceFill(['game_data_version' => GameDataVersion::current()])->save();

            $summary = self::initialStock($config);
            $rows = [];
            foreach ($summary as $resId => $amount) {
                $rows[] = ['city_id' => $city->id, 'resource_id' => $resId, 'amount' => $amount];
            }
            if ($rows) {
                CityResource::insert($rows);
            }

            // 审计:只在真正建城时写一次。本方法每个请求都会被调用(兜底老账号),
            // 但上面命中 $existing 会直接 return,不会走到这里,所以同一玩家只会有一条 CITY.CREATE
            AuditLogger::record(AuditAction::CITY_CREATE, 'success', [
                'actor_id'    => $user->id,
                'user_id'     => $user->id,
                'city_id'     => $city->id,
                'entity_type' => 'city',
                'entity_id'   => (string) $city->id,
                'after_json'  => ['revision' => 0, 'population' => $city->population, 'money' => (float) $city->money],
                // 初始资源摘要 + 来源:后台可改数值,记下来才能回答「这号开局到底给了多少、按哪套配置给的」
                'metadata_json' => [
                    'resources'                => $summary,
                    'initial_resources_source' => $config === null ? 'default' : 'game_setting',
                    'mapWidth'                 => $city->map_width,
                    'mapHeight'                => $city->map_height,
                ],
            ]);

            return $city;
        });
    }

    // 读后台设定;缺行 / 空对象 / 脏到无法使用 → null(调用方回退硬编码默认)。
    // 显式传 [] 作为默认值:缺行时拿到的是空数组而不是登记默认值,才能区分「没配」与「配了」
    private static function initialResourceConfig(): ?array
    {
        $config = GameSetting::get(GameSetting::INITIAL_RESOURCES, []);

        return is_array($config) && $config !== [] ? $config : null;
    }

    // 初始资金:配置里有 money 就用配置,否则维持历史的随机区间
    private static function initialMoney(?array $config): float
    {
        if ($config !== null && isset($config[ResourceCode::MONEY])) {
            return (float) $config[ResourceCode::MONEY];
        }

        return (float) random_int(SimConstants::START_MONEY[0], SimConstants::START_MONEY[1]);
    }

    // 初始库存资源(money 不进 city_resources,它是 cities.money 列)
    private static function initialStock(?array $config): array
    {
        if ($config === null) {
            $stock = [];
            foreach (SimConstants::START_RESOURCES as $resId => [$lo, $hi]) {
                $stock[$resId] = random_int($lo, $hi);
            }

            return $stock;
        }

        $stock = $config;
        unset($stock[ResourceCode::MONEY]);

        return $stock;
    }
}
