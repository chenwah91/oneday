<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

// 会话相关:当前用户 / 登出 / CSRF cookie
class SessionController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::ok([
            'data' => ['user' => ['id' => $user->id, 'username' => $user->username, 'email' => $user->email]],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        AuditLogger::record(AuditAction::AUTH_LOGOUT, 'success', [
            'actor_id' => $userId,
            'user_id'  => $userId,
        ]);

        return ApiResponse::ok(['data' => ['loggedOut' => true]]);
    }

    // 空响应,借 web 中间件下发 XSRF-TOKEN cookie
    public function csrfCookie(): Response
    {
        return response()->noContent();
    }
}
