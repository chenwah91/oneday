<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// 登录:用户名 + 密码,成功后重建 session
class LoginController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt(['username' => $credentials['username'], 'password' => $credentials['password']])) {
            AuditLogger::record(AuditAction::AUTH_LOGIN_FAILED, 'failed', [
                'reason_code'   => 'BAD_CREDENTIALS',
                'metadata_json' => ['username' => $credentials['username']],
            ]);

            return ApiResponse::fail(ErrorCode::BAD_CREDENTIALS, 401);
        }

        $request->session()->regenerate();
        $user = Auth::user();

        AuditLogger::record(AuditAction::AUTH_LOGIN_SUCCESS, 'success', [
            'actor_id' => $user->id,
            'user_id'  => $user->id,
        ]);

        return ApiResponse::ok([
            'data' => ['user' => ['id' => $user->id, 'username' => $user->username, 'email' => $user->email]],
        ]);
    }
}
