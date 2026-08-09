<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// 玩家城市
class City extends Model
{
    protected $fillable = ['user_id', 'name', 'revision', 'last_simulated_at', 'money', 'population', 'map_width', 'map_height'];

    protected function casts(): array
    {
        return ['last_simulated_at' => 'datetime', 'money' => 'decimal:2'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function resources(): HasMany { return $this->hasMany(CityResource::class); }
    public function buildingInstances(): HasMany { return $this->hasMany(CityBuildingInstance::class); }
}
