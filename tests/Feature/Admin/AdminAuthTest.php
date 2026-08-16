<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 后台独立会话(2026-08-15):/api/admin/auth/login 走 admin guard,与玩家的 web guard 互不覆盖;
// 且后台只接受管理员账号(CLAUDE §43 / §63)。
//
// 这组用例钉死的是本次改动的两条核心价值:
//   ① 同一个浏览器可以同时挂着「玩家已登录」和「管理员已登录」,谁也踢不掉谁;
//   ② 玩家账号即使用户名密码全对,也进不了后台。
class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'password123';

    // 建一个指定角色的用户。role 不可批量赋值,只能 forceFill 显式写入
    private function userWithRole(string $role, string $username): User
    {
        $user = User::create([
            'username' => $username, 'name' => $username,
            'email' => $username.'@example.com', 'password' => self::PASSWORD,
        ]);
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    private function adminLogin(string $username, string $password = self::PASSWORD): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/admin/auth/login', ['username' => $username, 'password' => $password]);
    }

    // ---------- ① 管理员经后台端点登录 ----------

    public function test_admin_can_login_through_admin_endpoint_and_access_admin_api(): void
    {
        $admin = $this->userWithRole(Role::ADMIN, 'adminlogin');

        $res = $this->adminLogin('adminlogin');
        $res->assertOk()->assertJson(['success' => true, 'data' => ['user' => ['username' => 'adminlogin']]]);

        // 登录态落在 admin guard 上,web guard 保持空 —— 后台登录不会顺带把人登进游戏
        $this->assertTrue(Auth::guard('admin')->check());
        $this->assertGuest('web');

        $this->getJson('/api/admin/me')->assertOk()->assertJson(['data' => ['username' => 'adminlogin', 'role' => Role::ADMIN]]);

        $audit = DB::table('audit_logs')->latest('id')->first();
        $this->assertSame('ADMIN.LOGIN', $audit->action);
        $this->assertSame('admin', $audit->actor_type);
        $this->assertSame($admin->id, (int) $audit->actor_id);
    }

    // support(最低后台角色)同样能登进后台,权限梯度由 EnsureAdmin 逐端点判
    public function test_support_can_login_through_admin_endpoint(): void
    {
        $this->userWithRole(Role::SUPPORT, 'supportlogin');

        $this->adminLogin('supportlogin')->assertOk();
        $this->getJson('/api/admin/me')->assertOk()->assertJson(['data' => ['role' => Role::SUPPORT]]);
    }

    // ---------- ② 玩家账号密码全对也进不了后台 ----------

    public function test_player_with_correct_password_cannot_login_to_admin(): void
    {
        $player = $this->userWithRole(Role::PLAYER, 'realplayer');

        $this->adminLogin('realplayer')
            ->assertStatus(403)
            ->assertJson(['success' => false, 'error' => 'FORBIDDEN']);

        // 关键:被拒时**任何** guard 都不能留下登录态(用 validate() 而不是 attempt() 的意义)
        $this->assertGuest('admin');
        $this->assertGuest('web');

        $audit = DB::table('audit_logs')->latest('id')->first();
        $this->assertSame('SECURITY.AUTHORIZATION_FAILED', $audit->action);
        $this->assertSame('NOT_ADMIN', $audit->reason_code);
        $this->assertSame($player->id, (int) $audit->actor_id);
        $meta = json_decode($audit->metadata_json, true);
        $this->assertSame(Role::PLAYER, $meta['role']);
        $this->assertSame('api/admin/auth/login', $meta['path']);
    }

    // 库里出现未知角色值(脏数据 / 人为写入)时一律按非后台人员拒绝(Fail Closed,与 Role::isStaff 同口径)
    public function test_unknown_role_cannot_login_to_admin(): void
    {
        $weird = $this->userWithRole(Role::PLAYER, 'weirdrole');
        $weird->forceFill(['role' => 'wizard'])->save();

        $this->adminLogin('weirdrole')->assertStatus(403)->assertJson(['error' => 'FORBIDDEN']);
        $this->assertGuest('admin');
    }

    // ---------- ③ 密码错的响应三种情况完全一致(不给账号枚举留缝)----------

    public function test_bad_credentials_response_is_identical_for_admin_player_and_unknown_account(): void
    {
        $this->userWithRole(Role::ADMIN, 'shapeadmin');
        $this->userWithRole(Role::PLAYER, 'shapeplayer');

        $bodies = [];
        foreach (['shapeadmin', 'shapeplayer', 'nosuchuser'] as $username) {
            $res = $this->adminLogin($username, 'wrongpassword');
            $res->assertStatus(401)->assertJson(['success' => false, 'error' => 'BAD_CREDENTIALS']);

            // request_id 每次不同,是唯一允许有差异的字段,比对前剔除
            $body = $res->json();
            unset($body['request_id']);
            $bodies[] = $body;
        }

        $this->assertSame($bodies[0], $bodies[1]);
        $this->assertSame($bodies[1], $bodies[2]);
        $this->assertGuest('admin');
    }

    // 失败限流与玩家登录**共用**同一个按账号计数器:换一道门不该多送 5 次猜测机会
    public function test_failed_admin_login_shares_account_lockout_with_player_login(): void
    {
        $this->userWithRole(Role::ADMIN, 'lockoutadmin');

        for ($i = 0; $i < 5; $i++) {
            $this->adminLogin('lockoutadmin', 'wrongpassword')->assertStatus(401);
        }

        // 第 6 次:后台门与玩家门都应该已经被同一个计数器锁住
        $this->adminLogin('lockoutadmin')->assertStatus(429)->assertJson(['error' => 'TOO_MANY_REQUESTS']);
        $this->postJson('/api/auth/login', ['username' => 'lockoutadmin', 'password' => self::PASSWORD])
            ->assertStatus(429);
    }

    // ---------- ④ 双身份并存(本次改动的核心价值)----------

    public function test_player_and_admin_sessions_coexist_in_the_same_browser(): void
    {
        $this->userWithRole(Role::PLAYER, 'dualplayer');
        $this->userWithRole(Role::ADMIN, 'dualadmin');

        // 同一个测试客户端 = 同一个浏览器 session
        $this->postJson('/api/auth/login', ['username' => 'dualplayer', 'password' => self::PASSWORD])->assertOk();
        $this->adminLogin('dualadmin')->assertOk();

        // 后登录的管理员没有把玩家挤掉
        $this->getJson('/api/me')->assertOk()->assertJson(['data' => ['user' => ['username' => 'dualplayer']]]);
        // 后台端点解析到的是管理员,不是同一 session 里的那个玩家
        $this->getJson('/api/admin/me')->assertOk()->assertJson(['data' => ['username' => 'dualadmin']]);
        // 顺序反过来再验一次:两个身份互不覆盖,与请求先后无关
        $this->getJson('/api/me')->assertOk()->assertJson(['data' => ['user' => ['username' => 'dualplayer']]]);

        // 两把锁的登录键同时存在于同一个 session 里,靠键名区分(admin guard 的实现基础)
        $store = $this->app['session.store'];
        $this->assertTrue($store->has(Auth::guard('web')->getName()));
        $this->assertTrue($store->has(Auth::guard('admin')->getName()));
    }

    // 反过来:管理员先登后台,玩家再登游戏 —— 这正是用户实测炸掉的那个顺序
    public function test_player_login_after_admin_login_does_not_kick_admin_out(): void
    {
        $this->userWithRole(Role::PLAYER, 'laterplayer');
        $this->userWithRole(Role::ADMIN, 'earlyadmin');

        $this->adminLogin('earlyadmin')->assertOk();
        $this->postJson('/api/auth/login', ['username' => 'laterplayer', 'password' => self::PASSWORD])->assertOk();

        // 改动前:这里会变成 403 NOT_ADMIN role=player
        $this->getJson('/api/admin/me')->assertOk()->assertJson(['data' => ['username' => 'earlyadmin']]);
        $this->getJson('/api/me')->assertOk()->assertJson(['data' => ['user' => ['username' => 'laterplayer']]]);
    }

    // ---------- ⑤ 后台登出只退管理员,玩家会话不受影响 ----------

    public function test_admin_logout_does_not_touch_the_player_session(): void
    {
        $this->userWithRole(Role::PLAYER, 'keepplayer');
        $this->userWithRole(Role::ADMIN, 'byeadmin');

        $this->postJson('/api/auth/login', ['username' => 'keepplayer', 'password' => self::PASSWORD])->assertOk();
        $this->adminLogin('byeadmin')->assertOk();

        $store = $this->app['session.store'];
        $tokenBefore = $store->token();

        $this->postJson('/api/admin/auth/logout')->assertOk()->assertJson(['data' => ['logged_out' => true]]);

        // 后台身份没了
        $this->getJson('/api/admin/me')->assertStatus(401)->assertJson(['error' => 'AUTH_REQUIRED']);
        $this->assertGuest('admin');

        // 玩家身份仍然有效 —— 这是「绝不 session()->invalidate()」的直接后果。
        // 光断言 /api/me 还是 200 不够(测试环境里 guard 实例会缓存 user),
        // 必须直接看 session:玩家的登录键还在、CSRF token 没被换掉,才证明没动玩家那张桌子
        $this->getJson('/api/me')->assertOk()->assertJson(['data' => ['user' => ['username' => 'keepplayer']]]);
        $this->assertTrue($store->has(Auth::guard('web')->getName()));
        $this->assertFalse($store->has(Auth::guard('admin')->getName()));
        $this->assertSame($tokenBefore, $store->token());
    }

    // 上面那条的**对称面**:玩家登出同样不该掀掉管理员的桌子。
    //
    // 这条是浏览器实测抓出来的真实缺陷(2026-08-15):SessionController::logout 原本用
    // session()->invalidate() 清空整个 session,把 login_admin_* 一起抹掉 —— 管理员在后台干活时,
    // 只要在游戏页点一下「退出登录」,后台当场 401。保护只做了单向,不符合「后台要和用户分开」。
    // 修法是改用 regenerate()(换 id、保留数据)。谁把它改回 invalidate(),这条就会红。
    public function test_player_logout_does_not_touch_the_admin_session(): void
    {
        $this->userWithRole(Role::PLAYER, 'byeplayer');
        $this->userWithRole(Role::ADMIN, 'keepadmin');

        $this->postJson('/api/auth/login', ['username' => 'byeplayer', 'password' => self::PASSWORD])->assertOk();
        $this->adminLogin('keepadmin')->assertOk();

        $store = $this->app['session.store'];

        $this->postJson('/api/auth/logout')->assertOk()->assertJson(['data' => ['logged_out' => true]]);

        // 玩家身份没了
        $this->getJson('/api/me')->assertStatus(401);
        $this->assertGuest('web');

        // 管理员身份仍然有效。同样不能只看 /api/admin/me 的状态码(guard 实例会缓存 user),
        // 必须直接断言 session 里 admin 的登录键还在
        $this->getJson('/api/admin/me')->assertOk()->assertJson(['data' => ['username' => 'keepadmin']]);
        $this->assertTrue($store->has(Auth::guard('admin')->getName()));
        $this->assertFalse($store->has(Auth::guard('web')->getName()));
    }

    // 玩家登出仍然要换 session id(防会话固定):改成「什么都不做」这条会红
    public function test_player_logout_still_rotates_the_session_id(): void
    {
        $this->userWithRole(Role::PLAYER, 'rotateplayer');
        $this->postJson('/api/auth/login', ['username' => 'rotateplayer', 'password' => self::PASSWORD])->assertOk();

        $store = $this->app['session.store'];
        $idBefore = $store->getId();

        $this->postJson('/api/auth/logout')->assertOk();

        $this->assertNotSame($idBefore, $store->getId(), '登出必须换掉 session id');
    }

    // ⚠️ 上面那条**不够**:invalidate() / regenerate() / regenerate(true) 三种写法下 id 都会变,
    // 它一种都区分不了。真正要钉的是「旧 id 那一行有没有被销毁」——
    // 而这件事**在 HTTP 层测不出来**:Laravel 的测试客户端从不回传 session cookie
    // (MakesHttpRequests 只带 defaultCookies),每个请求 StartSession 都 setId(null) 生成新 id,
    // 会话「延续」靠的是 Store 单例内存属性的 array_replace 合并。于是测试里根本不存在
    // 「上一个请求的那个 id」,拿旧 id 重放这个场景无法表达。
    //
    // 所以这里退一步用**源码断言**钉住写法(与 EnumCodeTest 钉 service-worker.js 的 CACHE 常量同一先例),
    // 并在下面用 Store 层的对照实验说明三者差别 —— 两条合起来才拦得住回改。
    //
    // 背景(对抗性审查 2026-08-16 抓出):一度改成 regenerate()(= migrate(false)),它**不销毁旧记录**——
    // logout() 摘登录键只发生在内存属性里,请求末尾被写到**新** id 上,旧 id 那行原封不动留着
    // login_web_* 和 login_admin_*。登出于是成了「会话分叉」而不是「会话吊销」:cookie 一旦泄露,
    // 点登出这个唯一的自救动作等于没做(CWE-613 / ASVS 3.3.1)。
    public function test_logout_paths_destroy_the_old_session_record_in_source(): void
    {
        $cases = [
            'app/Http/Controllers/Auth/SessionController.php' => '玩家登出',
            'app/Http/Middleware/EnsureNotBanned.php'         => '封禁踢下线',
        ];

        foreach ($cases as $path => $label) {
            $src = file_get_contents(base_path($path));

            // 必须是 regenerate(true):销毁旧行 + 换 id + 不 flush(另一个身份照样存活)
            $this->assertStringContainsString('session()->regenerate(true)', $src,
                "{$label}({$path})必须用 regenerate(true),否则旧 session id 仍是一个完整可重放的会话");

            // 不得退回 invalidate():会 flush 整个 session,把同浏览器里另一个身份一起抹掉
            $this->assertStringNotContainsString('session()->invalidate()', $src,
                "{$label}({$path})不得用 invalidate(),它会连坐清掉同浏览器里的另一个身份");
        }
    }

    // 上一条源码断言的**依据**:在 Store 层把三种写法的差别摆出来,证明只有 regenerate(true)
    // 同时满足「销毁旧行」与「保留另一个身份」。哪天 Laravel 改了 Store 语义,这条会先红
    public function test_session_store_semantics_behind_the_regenerate_true_choice(): void
    {
        $make = function () {
            /** @var \Illuminate\Session\Store $store */
            $store = $this->app['session.store'];
            $store->setId(null);
            $store->start();
            $store->put('login_web_x', 7);
            $store->put('login_admin_x', 9);
            $store->save();

            return [$store, $store->getId(), $store->getHandler()];
        };

        // ① regenerate() —— 旧行**仍在**,而且两个身份都还在里面(这就是被审查抓出的回归)
        [$store, $oldId, $handler] = $make();
        $store->regenerate();
        $store->save();
        $stale = (string) $handler->read($oldId);
        $this->assertNotSame('', $stale, 'regenerate() 不销毁旧行 —— 这正是不能用它的原因');
        $this->assertStringContainsString('login_admin_x', $stale);

        // ② invalidate() —— 旧行没了,但**数据被 flush**,另一个身份当场消失
        [$store, $oldId, $handler] = $make();
        $store->invalidate();
        $store->save();
        $this->assertFalse($store->has('login_admin_x'), 'invalidate() 会 flush 掉另一个身份');

        // ③ regenerate(true) —— 旧行销毁,数据保留:两个目的同时满足 ✅
        [$store, $oldId, $handler] = $make();
        $store->regenerate(true);
        $store->save();
        $this->assertSame('', (string) $handler->read($oldId), 'regenerate(true) 必须销毁旧行');
        $this->assertTrue($store->has('login_admin_x'), 'regenerate(true) 不得 flush 掉另一个身份');
    }

    // EnsureNotBanned 的行为面:踢被封玩家时,不能连坐清掉同浏览器里的管理员
    public function test_ban_kick_keeps_the_admin_session(): void
    {
        $player = $this->userWithRole(Role::PLAYER, 'kickplayer');
        $this->userWithRole(Role::ADMIN, 'kickadmin');

        $this->postJson('/api/auth/login', ['username' => 'kickplayer', 'password' => self::PASSWORD])->assertOk();
        $this->adminLogin('kickadmin')->assertOk();

        $store = $this->app['session.store'];

        // 玩家被封(等同于管理员在后台点了封禁)
        $player->forceFill(['banned_at' => now(), 'ban_reason' => '测试封禁'])->save();
        // guard 实例会缓存 user,不清掉的话下一个请求看到的还是封禁前那份(真实 HTTP 每请求重新取)
        $this->app['auth']->forgetGuards();

        // 该玩家的下一次游戏请求撞上第二道闸
        $this->getJson('/api/city')->assertStatus(401)->assertJson(['error' => 'ACCOUNT_BANNED']);

        // ① 玩家确实被踢了
        $this->assertFalse($store->has(Auth::guard('web')->getName()));
        // ② 管理员会话原样存活 —— 用 invalidate() 的话这里会红(封禁把管理员自己也踢了)
        $this->assertTrue($store->has(Auth::guard('admin')->getName()), '封禁玩家不得连坐清掉管理员会话');
        $this->getJson('/api/admin/me')->assertOk()->assertJson(['data' => ['username' => 'kickadmin']]);
    }

    // 后台登录口豁免 EnsureNotBanned:浏览器里挂着一个**被封玩家**时,管理员照样登得进后台。
    // 不豁免的话,登录请求会在进 AdminAuthController 之前就按 web guard 判掉,
    // 管理员看到的是「账号已封禁」—— 而被封的是同浏览器里那个跟他毫无关系的玩家
    public function test_admin_can_login_while_a_banned_player_session_exists(): void
    {
        $player = $this->userWithRole(Role::PLAYER, 'bannedbystander');
        $this->userWithRole(Role::ADMIN, 'innocentadmin');

        $this->postJson('/api/auth/login', ['username' => 'bannedbystander', 'password' => self::PASSWORD])->assertOk();
        $player->forceFill(['banned_at' => now(), 'ban_reason' => '测试封禁'])->save();

        // 第一次就要成功(不豁免时第一次必然 401 ACCOUNT_BANNED)
        $this->adminLogin('innocentadmin')
            ->assertOk()
            ->assertJson(['data' => ['user' => ['username' => 'innocentadmin']]]);

        $this->getJson('/api/admin/me')->assertOk()->assertJson(['data' => ['username' => 'innocentadmin']]);
    }

    // 「已登录玩家在扫后台 API」必须留痕(§60/§67)。改用 auth:admin 之后这条路径不再经过
    // EnsureAdmin,Authenticate 提前抛异常 —— 不在异常处理里补写,这个入侵信号就整条消失了
    public function test_player_session_probing_admin_api_is_audited(): void
    {
        $player = $this->userWithRole(Role::PLAYER, 'prober');
        $this->postJson('/api/auth/login', ['username' => 'prober', 'password' => self::PASSWORD])->assertOk();

        $this->getJson('/api/admin/players')->assertStatus(401)->assertJson(['error' => 'AUTH_REQUIRED']);

        $audit = DB::table('audit_logs')->latest('id')->first();
        $this->assertSame('SECURITY.AUTHORIZATION_FAILED', $audit->action);
        // NOT_ADMIN(有后台会话但角色不够)与 NO_ADMIN_SESSION(压根没后台会话)刻意分开
        $this->assertSame('NO_ADMIN_SESSION', $audit->reason_code);
        $this->assertSame($player->id, (int) $audit->user_id);
    }

    // 反面:**未登录**访客打后台端点是常态噪音(扫描器 / 收藏夹),全记会把真信号淹掉
    public function test_guest_probing_admin_api_is_not_audited(): void
    {
        $before = DB::table('audit_logs')->count();

        $this->getJson('/api/admin/players')->assertStatus(401);

        $this->assertSame($before, DB::table('audit_logs')->count(), '未登录访客的 401 不该写审计');
    }

    // 未登录后台时打登出:auth:admin 先挡下,401
    public function test_admin_logout_requires_admin_session(): void
    {
        $this->postJson('/api/admin/auth/logout')->assertStatus(401)->assertJson(['error' => 'AUTH_REQUIRED']);
    }

    // ---------- ⑥ 被封禁的管理员账号登不进后台 ----------

    public function test_banned_admin_cannot_login_to_admin(): void
    {
        $admin = $this->userWithRole(Role::ADMIN, 'bannedadmin');
        $admin->forceFill(['banned_at' => now(), 'ban_reason' => '测试封禁'])->save();

        $this->adminLogin('bannedadmin')
            ->assertStatus(401)
            ->assertJson(['success' => false, 'error' => 'ACCOUNT_BANNED']);

        // 封禁分支同样不能留下任何登录态
        $this->assertGuest('admin');
        $this->assertGuest('web');

        $audit = DB::table('audit_logs')->latest('id')->first();
        // ADMIN.LOGIN_FAILED 而不是玩家侧的 AUTH.LOGIN_FAILED(两道门的审计完全分家)
        $this->assertSame('ADMIN.LOGIN_FAILED', $audit->action);
        $this->assertSame('ACCOUNT_BANNED', $audit->reason_code);
    }

    // ---------- 「玩家登入与 admin 登入完全分开」在审计上的落实(用户 2026-08-16)----------

    // 后台三个认证动作各有各的 action code,`WHERE action LIKE 'ADMIN.%'` 就是后台认证的全部活动;
    // 谁把哪一条改回复用 AUTH.*,这条就会红
    public function test_admin_auth_events_never_reuse_player_action_codes(): void
    {
        $this->userWithRole(Role::ADMIN, 'codeadmin');

        // ① 失败(密码错)
        $this->adminLogin('codeadmin', 'wrong-password')->assertStatus(401);
        $this->assertSame('ADMIN.LOGIN_FAILED', DB::table('audit_logs')->latest('id')->first()->action);

        // ② 成功
        $this->adminLogin('codeadmin')->assertOk();
        $this->assertSame('ADMIN.LOGIN', DB::table('audit_logs')->latest('id')->first()->action);

        // ③ 登出
        $this->postJson('/api/admin/auth/logout')->assertOk();
        $this->assertSame('ADMIN.LOGOUT', DB::table('audit_logs')->latest('id')->first()->action);

        // 整个后台认证过程一条 AUTH.* 都不该留下 —— 那是玩家那道门的命名空间
        $playerCodes = DB::table('audit_logs')->where('action', 'like', 'AUTH.%')->count();
        $this->assertSame(0, $playerCodes, '后台认证不得写入任何 AUTH.* 审计');
    }

    // 反向:玩家那道门照旧写 AUTH.*,没有被这次分家波及
    public function test_player_auth_events_still_use_player_action_codes(): void
    {
        $this->userWithRole(Role::PLAYER, 'codeplayer');

        $this->postJson('/api/auth/login', ['username' => 'codeplayer', 'password' => self::PASSWORD])->assertOk();
        $this->assertSame('AUTH.LOGIN_SUCCESS', DB::table('audit_logs')->latest('id')->first()->action);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->assertSame('AUTH.LOGOUT', DB::table('audit_logs')->latest('id')->first()->action);

        $this->assertSame(0, DB::table('audit_logs')->where('action', 'like', 'ADMIN.%')->count());
    }

    // ---------- EnsureNotBanned 在新 guard 下的落点(实测钉死)----------

    // 中间件顺序的关键事实:Authenticate(auth:admin)在 Laravel 的 middlewarePriority 里,
    // 排序后跑在**追加到 web 组末尾**的 EnsureNotBanned 之前。于是 auth:admin 先把默认 guard
    // 切成 admin,EnsureNotBanned 里的 $request->user() 解析到的就是**后台身份**。
    // 结论:封禁的第二道闸对后台会话同样生效,不存在「换个 guard 就绕过封禁」的缝。
    public function test_banned_non_staff_holding_admin_session_is_kicked_by_ensure_not_banned(): void
    {
        $u = $this->userWithRole(Role::PLAYER, 'bannedholder');
        $u->forceFill(['banned_at' => now(), 'ban_reason' => '测试封禁'])->save();

        // 从库里重新取(等同于 session guard 每个请求从 provider 拿人)
        $this->actingAs(User::find($u->id), 'admin');

        // EnsureNotBanned 排在 EnsureAdmin 之前,所以这里是 401 ACCOUNT_BANNED 而不是 403 FORBIDDEN
        $this->getJson('/api/admin/me')->assertStatus(401)->assertJson(['error' => 'ACCOUNT_BANNED']);
    }

    // 既有裁决不变(EnsureNotBanned 顶部注释):后台人员豁免第二道闸,防「改库封了 admin → 整个运营团队被锁在门外」。
    // 登录口不豁免(见 test_banned_admin_cannot_login_to_admin),两者合起来才是完整口径:
    // 被封的管理员**登不进来**,但已经在里面的不会被这道闸弹出去
    public function test_banned_staff_holding_admin_session_stays_exempt(): void
    {
        $u = $this->userWithRole(Role::ADMIN, 'bannedstaffhold');
        $u->forceFill(['banned_at' => now(), 'ban_reason' => '测试封禁'])->save();

        $this->actingAs(User::find($u->id), 'admin');
        $this->getJson('/api/admin/me')->assertOk()->assertJson(['data' => ['username' => 'bannedstaffhold']]);
    }

    // ---------- 玩家会话对后台一律无效(新语义:401 而不是原先的 403)----------

    public function test_player_session_alone_cannot_reach_admin_api(): void
    {
        $this->userWithRole(Role::PLAYER, 'onlyplayer');
        $this->postJson('/api/auth/login', ['username' => 'onlyplayer', 'password' => self::PASSWORD])->assertOk();

        // 只有 web 会话 → auth:admin 直接 401(压根没走到 EnsureAdmin)。
        // 改动前这里是 403 FORBIDDEN:那时两边共用 web guard,请求进得到 EnsureAdmin 才被角色判掉
        $this->getJson('/api/admin/me')->assertStatus(401)->assertJson(['error' => 'AUTH_REQUIRED']);
        $this->getJson('/api/admin/players')->assertStatus(401);
    }

    // 管理员的**游戏**会话同样不算后台会话:必须从后台的门单独登一次
    public function test_admin_game_session_is_not_an_admin_session(): void
    {
        $this->userWithRole(Role::ADMIN, 'gameadmin');
        $this->postJson('/api/auth/login', ['username' => 'gameadmin', 'password' => self::PASSWORD])->assertOk();

        $this->getJson('/api/admin/me')->assertStatus(401)->assertJson(['error' => 'AUTH_REQUIRED']);
    }

    // ---------- EnsureAdmin 仍是第二道闸 ----------

    // auth:admin 会把默认 guard 切到 admin,因此 EnsureAdmin / 各 Controller 里的
    // $request->user() 自然解析 admin guard,无需改动 —— 这条用例就是钉住这个前提。
    // 现实场景:管理员登进后台之后被降级(或 role 被写脏),后续请求靠 EnsureAdmin 兜住(Fail Closed)
    public function test_ensure_admin_rejects_demoted_account_holding_an_admin_session(): void
    {
        $user = $this->userWithRole(Role::ADMIN, 'demoteme');
        $this->adminLogin('demoteme')->assertOk();
        $this->getJson('/api/admin/me')->assertOk();

        // 登录之后被降级。这里用 actingAs(..., 'admin') 把降级后的用户重新挂到 admin guard 上 ——
        // 线上每个请求都会按 session 里的登录键**重新从库里取一次**用户,拿到的自然是新角色;
        // 而测试进程里 guard 实例把 User 对象缓在内存里,不重挂就还是那份 role=admin 的旧快照。
        // 断言的对象是 EnsureAdmin(持有后台会话但角色不够 → 403),不是「几时重新读库」
        $user->forceFill(['role' => Role::PLAYER])->save();
        $this->actingAs($user, 'admin');

        $this->getJson('/api/admin/players')->assertStatus(403)->assertJson(['error' => 'FORBIDDEN']);

        $audit = DB::table('audit_logs')->latest('id')->first();
        $this->assertSame('SECURITY.AUTHORIZATION_FAILED', $audit->action);
        $this->assertSame('NOT_ADMIN', $audit->reason_code);
    }

    // 后台请求解析的是 admin guard 而不是同一 session 里的 web guard:
    // 两个 guard 上挂着**不同的人**时,后台端点必须报出管理员那一个
    public function test_admin_endpoint_resolves_admin_guard_when_two_identities_coexist(): void
    {
        $support = $this->userWithRole(Role::SUPPORT, 'twoheadsupport');
        $this->userWithRole(Role::PLAYER, 'twoheadplayer');

        $this->postJson('/api/auth/login', ['username' => 'twoheadplayer', 'password' => self::PASSWORD])->assertOk();
        $this->adminLogin('twoheadsupport')->assertOk();

        $res = $this->getJson('/api/admin/me')->assertOk();
        $this->assertSame('twoheadsupport', $res->json('data.username'));
        $this->assertSame($support->id, $res->json('data.id'));
        $this->assertSame(Role::SUPPORT, $res->json('data.role'));
    }
}
