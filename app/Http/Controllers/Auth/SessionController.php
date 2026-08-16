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
            // created_at 直接给 Carbon 实例,由 Laravel 序列化成 ISO 8601(W12:前端个人资料要显示注册时间)
            'data' => ['user' => ['id' => $user->id, 'username' => $user->username, 'email' => $user->email, 'created_at' => $user->created_at]],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        Auth::guard('web')->logout();

        // ⚠️ 三个候选各自会坏在哪,一次写清楚,别再改回来:
        //
        //   invalidate()      = flush() + migrate(true)。**清空数据** —— 会把同一个浏览器里
        //                       管理员的登录键(login_admin_*)一起抹掉,后台当场 401。
        //                       这正是 AdminAuthController::logout「退管理员的门不动玩家的桌子」
        //                       的对称面:退玩家的门,同样不该掀管理员的桌子。
        //   regenerate()      = migrate(**false**) —— 只换 id、**不销毁旧记录**。
        //                       上面 logout() 摘登录键只发生在内存属性里,请求末尾被写到**新** id 上,
        //                       旧 id 那一行原封不动留着 login_web_* 和 login_admin_*。
        //                       登出就成了「会话分叉」而不是「会话吊销」:cookie 一旦泄露,
        //                       点登出这个唯一的自救动作等于没做(CWE-613 / ASVS 3.3.1)。
        //   regenerate(true)  = migrate(**true**) —— 销毁旧记录 + 换 id,但**不 flush**。✅
        //                       旧 id 当场失效(吊销做到了),管理员的登录键留在内存里随新 id 落库
        //                       (不掀桌子也做到了)。两个目的同时满足,所以选它。
        //
        // 副作用:regenerate() 内部会 regenerateToken(),后台页手上的 CSRF token 失效一拍;
        // 两边前端都是每次请求现读 XSRF-TOKEN cookie,响应已带新 cookie,下一次请求自愈(与后台登录同一取舍)。
        $request->session()->regenerate(true);

        AuditLogger::record(AuditAction::AUTH_LOGOUT, 'success', [
            'actor_id' => $userId,
            'user_id'  => $userId,
        ]);

        // 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)
        return ApiResponse::ok(['data' => ['logged_out' => true]]);
    }

    // 空响应,借 web 中间件下发 XSRF-TOKEN cookie
    public function csrfCookie(): Response
    {
        return response()->noContent();
    }
}
