<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// 城市建筑实例
class CityBuildingInstance extends Model
{
    protected $fillable = ['city_id', 'building_id', 'level', 'x', 'y', 'status'];
}
