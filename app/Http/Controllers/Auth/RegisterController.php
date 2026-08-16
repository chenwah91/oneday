<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

// 注册:创建账号并自动登录(session)
class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::create([
            'username' => $data['username'],
            'name'     => $data['username'],
            'email'    => $data['email'],
            'phone'    => $data['phone'] ?? null,
            'password' => $data['password'], // 模型 hashed cast 自动哈希
        ]);

        // 显式指定 web guard(不用默认 guard):项目还有一个后台专用的 admin guard,
        // 注册自动登录只能建立玩家身份,绝不能因为默认 guard 被切过而写进后台那把锁
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        \App\Game\City\CityFactory::createForUser($user);

        AuditLogger::record(AuditAction::AUTH_REGISTER, 'success', [
            'actor_id' => $user->id,
            'user_id'  => $user->id,
            'entity_type' => 'user',
            'entity_id'   => (string) $user->id,
        ]);

        return ApiResponse::ok([
            'data' => ['user' => ['id' => $user->id, 'username' => $user->username, 'email' => $user->email]],
        ], 201);
    }
}
