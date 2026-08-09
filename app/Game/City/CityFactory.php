<?php

namespace App\Game\City;

use App\Game\Definition\GameDataVersion;
use App\Game\Simulation\SimConstants;
use App\Models\City;
use App\Models\CityResource;
use App\Models\User;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

// 建城:事务内创建城市 + 随机初始资源(幂等)
class CityFactory
{
    public static function createForUser(User $user): City
    {
        $existing = City::where('user_id', $user->id)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($user) {
            $city = City::create([
                'user_id'           => $user->id,
                'name'              => $user->username . '的城市',
                'revision'          => 0,
                'last_simulated_at' => now(),
                'money'             => random_int(SimConstants::START_MONEY[0], SimConstants::START_MONEY[1]),
                'population'        => SimConstants::START_POPULATION,
                'map_width'         => SimConstants::MAP_W,
                'map_height'        => SimConstants::MAP_H,
            ]);

            // 记下这座城「以哪一版数值开局」(§64):以后数值改版了,还能解释老城当初的开局资源是怎么来的。
            // game_data_version 不在 City::$fillable 里(它不是玩家/请求能决定的字段),所以用 forceFill 显式写入。
            $city->forceFill(['game_data_version' => GameDataVersion::current()])->save();

            $rows = [];
            $summary = [];
            foreach (SimConstants::START_RESOURCES as $resId => [$lo, $hi]) {
                $amount = random_int($lo, $hi);
                $rows[] = ['city_id' => $city->id, 'resource_id' => $resId, 'amount' => $amount];
                $summary[$resId] = $amount;
            }
            CityResource::insert($rows);

            // 审计:只在真正建城时写一次。本方法每个请求都会被调用(兜底老账号),
            // 但上面命中 $existing 会直接 return,不会走到这里,所以同一玩家只会有一条 CITY.CREATE
            AuditLogger::record(AuditAction::CITY_CREATE, 'success', [
                'actor_id'    => $user->id,
                'user_id'     => $user->id,
                'city_id'     => $city->id,
                'entity_type' => 'city',
                'entity_id'   => (string) $city->id,
                'after_json'  => ['revision' => 0, 'population' => $city->population, 'money' => (float) $city->money],
                // 初始资源摘要:随机生成,记下来才能回答「这号开局到底给了多少」
                'metadata_json' => ['resources' => $summary, 'mapWidth' => $city->map_width, 'mapHeight' => $city->map_height],
            ]);

            return $city;
        });
    }
}
