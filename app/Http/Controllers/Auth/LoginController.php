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
            'username' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt(['username' => $credentials['username'], 'password' => $credentials['password']])) {
            AuditLogger::record(AuditAction::AUTH_LOGIN_FAILED, 'failed', [
                'reason_code'   => 'BAD_CREDENTIALS',
                // 审计中的用户名做截断保护(即便校验已限制长度,双重防御避免异常输入撑大记录)
                'metadata_json' => ['username' => \Illuminate\Support\Str::limit((string) $credentials['username'], 190, '')],
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
