<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // api 分组基础限流:每 IP 每分钟 60 次(登录等更严的限流在 P2 单独定义)
        \Illuminate\Support\Facades\RateLimiter::for('api', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by($request->ip());
        });

        // 登录限流:同一 用户名+IP 每分钟 5 次(用户名过长时截断,避免撑爆限流缓存 key)
        \Illuminate\Support\Facades\RateLimiter::for('auth', function (\Illuminate\Http\Request $request) {
            $key = \Illuminate\Support\Str::substr(strtolower((string) $request->input('username')), 0, 190).'|'.$request->ip();

            return \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by($key);
        });

        // 注册限流:同 IP 每分钟 10 次,减缓「用户名/邮箱是否已占用」的账号枚举探测
        \Illuminate\Support\Facades\RateLimiter::for('register', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(10)->by($request->ip());
        });
    }
}
