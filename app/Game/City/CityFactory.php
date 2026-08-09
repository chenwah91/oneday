<?php

namespace App\Game\City;

use App\Game\Simulation\SimConstants;
use App\Models\City;
use App\Models\CityResource;
use App\Models\User;
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

            $rows = [];
            foreach (SimConstants::START_RESOURCES as $resId => [$lo, $hi]) {
                $rows[] = ['city_id' => $city->id, 'resource_id' => $resId, 'amount' => random_int($lo, $hi)];
            }
            CityResource::insert($rows);

            return $city;
        });
    }
}
