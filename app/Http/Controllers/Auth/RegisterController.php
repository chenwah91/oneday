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

        Auth::login($user);
        $request->session()->regenerate();

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
