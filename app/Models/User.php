<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'username',
        'name',
        'email',
        'phone',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // 用户的城市(一对一,注册时由 CityFactory 建城)
    public function city(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\City::class);
    }

    // ---------- 角色 / 权限(CLAUDE §63,权限表见 App\Support\Role) ----------
    // ⚠️ role 绝不能加入上面的 $fillable:一旦可批量赋值,注册/更新接口带个 role 字段就是自助提权。
    // 写 role 的唯一合法路径是 forceFill(见 admin:promote 命令)。

    // 是否具备某权限(未知角色一律 false,Fail Closed)
    public function hasPermission(string $permission): bool
    {
        return \App\Support\Role::allows(is_string($this->role) ? $this->role : null, $permission);
    }

    // 是否为后台人员(support 及以上)
    public function isStaff(): bool
    {
        return \App\Support\Role::isStaff(is_string($this->role) ? $this->role : null);
    }
}
