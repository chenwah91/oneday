<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// 城市资源存量
class CityResource extends Model
{
    public $timestamps = false;
    protected $fillable = ['city_id', 'resource_id', 'amount'];
    protected function casts(): array { return ['amount' => 'decimal:4']; }
}
