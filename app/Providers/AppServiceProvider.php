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

        // 登录限流:此处仅作粗粒度按 IP 的 DoS 防护(每 IP 每分钟 20 次),
        // 真正按账号、与 IP 无关的失败次数限制在 LoginController 中实现(见该文件顶部注释)
        \Illuminate\Support\Facades\RateLimiter::for('auth', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(20)->by($request->ip());
        });

        // 注册限流:同 IP 每分钟 10 次,减缓「用户名/邮箱是否已占用」的账号枚举探测
        \Illuminate\Support\Facades\RateLimiter::for('register', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(10)->by($request->ip());
        });
    }
}
