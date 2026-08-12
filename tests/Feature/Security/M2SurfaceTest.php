<?php

namespace Tests\Feature\Security;

use App\Game\City\CityFactory;
use App\Models\CityBuildingInstance;
use App\Models\User;
use App\Support\Role;
use App\Support\SecurityLogger;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

// M2 攻击面的「横切防线」回归(C6):路由层的 CSRF / 限流、审计不可被抑制、Security Log 不泄敏感字段。
//
// 这些防线不在任何单个 Controller 里,靠端点自己的 Feature 测试验不到 ——
// 少挂一个中间件、或让审计写不进去,所有业务测试仍然全绿。
class M2SurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    // M2 新增/大改的全部状态变更端点(CLAUDE §47 CSRF + §48 限流的适用范围)
    private const MUTATION_ROUTES = [
        'api/city/build',
        'api/city/upgrade',
        'api/city/upgrade/cancel',
        'api/city/demolish',
        'api/city/workers/assign',
        'api/city/research',
        'api/city/era/upgrade',
        'api/admin/compensation',
        'api/admin/settings',
    ];

    // M2 触及的只读端点:GET 同样要限流(快照会跑结算,是最贵的 GET)
    private const READ_ROUTES = [
        'api/city',
        'api/definitions/technologies',
        'api/definitions/buildings',
        'api/admin/compensation/lookup',
        'api/admin/settings',
    ];

    private function routeFor(string $uri, string $method): RoutingRoute
    {
        foreach (app(Router::class)->getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return $route;
            }
        }

        $this->fail("路由 {$method} /{$uri} 没注册");
    }

    private function middlewareOf(string $uri, string $method): array
    {
        // 先把 HTTP Kernel 实例化:中间件组(web / 别名)是 Kernel 构造时注册进 Router 的,
        // 不实例化就只能拿到未展开的 ['web', 'auth:web', 'throttle:api'],验不出组里到底有没有 CSRF
        app(HttpKernel::class);

        return app(Router::class)->gatherRouteMiddleware($this->routeFor($uri, $method));
    }

    // 每个 POST 端点都必须真的挂着 CSRF 校验。
    // 测试期 Laravel 会跳过 CSRF 校验(runningUnitTests),所以只能从中间件栈上验 ——
    // 真正的 419 响应形状由 ExceptionRenderTest 覆盖
    public function test_every_m2_mutation_route_is_csrf_protected(): void
    {
        foreach (self::MUTATION_ROUTES as $uri) {
            $this->assertContains(
                ValidateCsrfToken::class,
                $this->middlewareOf($uri, 'POST'),
                "POST /{$uri} 不在 web 组里 = 没有 CSRF 防护"
            );
        }
    }

    // 每个 POST 端点都必须限流(CLAUDE §48 点名 Build / Upgrade / Demolish / Research)。
    // research / era/upgrade / upgrade/cancel 是 M2 新增的三个,最容易漏挂
    public function test_every_m2_mutation_route_is_rate_limited(): void
    {
        foreach (self::MUTATION_ROUTES as $uri) {
            $throttles = array_values(array_filter(
                $this->middlewareOf($uri, 'POST'),
                fn ($m) => is_string($m) && str_starts_with($m, ThrottleRequests::class.':')
            ));
            $this->assertNotEmpty($throttles, "POST /{$uri} 没挂任何限流器");
        }

        // 后台写操作额外叠一层 admin_write:管理员账号被盗时批量刷补偿/批量改全服数值要先撞上限。
        // 名单是**全部** POST api/admin/* 写端点(R1-B 走查发现 building-level/npc 两条漏挂,补上后全量锁死)
        foreach ([
            'api/admin/compensation',
            'api/admin/settings',
            'api/admin/definitions/building-level',
            'api/admin/definitions/npc',
            'api/admin/definitions/market',
            'api/admin/definitions/item',
            'api/admin/definitions/event',
        ] as $uri) {
            $this->assertContains(
                ThrottleRequests::class.':admin_write',
                $this->middlewareOf($uri, 'POST'),
                "POST /{$uri} 缺少 admin_write 限流"
            );
        }
    }

    public function test_m2_read_routes_are_rate_limited(): void
    {
        foreach (self::READ_ROUTES as $uri) {
            $throttles = array_values(array_filter(
                $this->middlewareOf($uri, 'GET'),
                fn ($m) => is_string($m) && str_starts_with($m, ThrottleRequests::class.':')
            ));
            $this->assertNotEmpty($throttles, "GET /{$uri} 没挂任何限流器");
        }
    }

    // 上面两条按名单查,漏写名单就查不到。这条反过来:遍历路由表,
    // api/* 下**每一条**路由都必须挂限流,豁免必须显式登记在下面的名单里(CLAUDE §48)。
    // 新端点忘挂 throttle 会在这里直接红,不依赖有没有人记得更新 MUTATION_ROUTES / READ_ROUTES。
    private const UNTHROTTLED_API_ROUTES = [
        // 健康检查探针:探活请求被节流会把监控打成误报,有意不限流(路由文件里有同样的注释)
        'api/health',
    ];

    public function test_every_api_route_is_rate_limited(): void
    {
        app(HttpKernel::class);
        $router = app(Router::class);
        $checked = 0;

        foreach ($router->getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/')) {
                continue;
            }
            // api/_boom / _forbidden / _csrf / _ping:仅非生产环境注册的探针,不算对外攻击面
            if (str_starts_with($uri, 'api/_')) {
                continue;
            }
            if (in_array($uri, self::UNTHROTTLED_API_ROUTES, true)) {
                continue;
            }

            $throttles = array_filter(
                $router->gatherRouteMiddleware($route),
                fn ($m) => is_string($m) && str_starts_with($m, ThrottleRequests::class.':')
            );
            $this->assertNotEmpty(
                $throttles,
                implode('|', $route->methods())." /{$uri} 没挂任何限流器(要豁免请登记进 UNTHROTTLED_API_ROUTES 并写明理由)"
            );
            $checked++;
        }

        // 防止上面的过滤条件写歪导致「一条都没查却全绿」
        $this->assertGreaterThan(15, $checked, '扫到的 api 路由太少,过滤条件可能写错了');
    }

    // 会话三兄弟的具体档位(C6 遗留:M1 时期这三条一条限流都没挂)。
    // /api/csrf-cookie 是**未登录**也能打的公开端点,每打一次就起一个 session,不限流等于免费 session 生成器
    public function test_session_routes_carry_expected_limiters(): void
    {
        $expected = [
            ['api/me',          'GET',  ThrottleRequests::class.':api'],
            ['api/csrf-cookie', 'GET',  ThrottleRequests::class.':api'],
            // 登出属认证域,比读端点略严(auth = 每 IP 每分钟 20 次)
            ['api/auth/logout', 'POST', ThrottleRequests::class.':auth'],
        ];

        foreach ($expected as [$uri, $method, $limiter]) {
            $this->assertContains($limiter, $this->middlewareOf($uri, $method), "{$method} /{$uri} 的限流档位不对");
        }
    }

    // 认证闸门:M2 端点没有一个允许匿名访问
    public function test_no_m2_route_is_reachable_anonymously(): void
    {
        foreach (self::MUTATION_ROUTES as $uri) {
            $this->postJson('/'.$uri, [])->assertStatus(401)->assertJson(['error' => 'AUTH_REQUIRED']);
        }
        foreach (self::READ_ROUTES as $uri) {
            $this->getJson('/'.$uri)->assertStatus(401)->assertJson(['error' => 'AUTH_REQUIRED']);
        }
    }

    // ---------- 审计不可被抑制(C6 发现的漏洞回归) ----------

    // 攻击:X-Request-ID 是客户端可控的,而 audit_logs.request_id 只有 CHAR(36)。
    // 修复前发一个 37~128 字符的 ID,AuditLogger 的 INSERT 会在 STRICT_TRANS_TABLES 下报「Data too long」,
    // 于是:后台探测被打成零留痕的 500(SECURITY.AUTHORIZATION_FAILED 写不进去,Security Log 也被跳过),
    // 经济 Mutation 则因审计写不进去被整笔回滚。
    // 修复:EnsureRequestId 只接受 <= 36 字符的合法 ID,超长一律退回服务器生成的 UUID。
    public function test_oversized_request_id_cannot_suppress_audit(): void
    {
        $forged = str_repeat('a', 64);

        // 1) 后台越权探测:必须仍是 403,并留下 SECURITY.AUTHORIZATION_FAILED
        $player = User::create(['username' => 'ridprobe', 'name' => 'ridprobe', 'email' => 'rid@x.com', 'password' => 'password123']);
        CityFactory::createForUser($player);

        $this->actingAs($player)->getJson('/api/admin/settings', ['X-Request-ID' => $forged])
            ->assertStatus(403)->assertJson(['error' => 'FORBIDDEN']);

        $denied = DB::table('audit_logs')->where('action', 'SECURITY.AUTHORIZATION_FAILED')->first();
        $this->assertNotNull($denied, '超长 X-Request-ID 把越权审计冲掉了');
        $this->assertSame(36, strlen((string) $denied->request_id), '落库的 request_id 必须仍在列宽之内');
        $this->assertNotSame($forged, $denied->request_id, '超长 ID 必须被替换成服务器生成的 UUID');

        // 2) 经济 Mutation:必须仍然成功并留下审计
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        $u = User::create(['username' => 'ridbuilder', 'name' => 'ridbuilder', 'email' => 'rid2@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        $this->unlockTechFor($city->id, 'R01');
        DB::table('city_resources')->updateOrInsert(['city_id' => $city->id, 'resource_id' => 'wood'], ['amount' => 500]);

        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'R01', 'x' => 10, 'y' => 10], ['X-Request-ID' => $forged])
            ->assertOk();

        $this->assertSame(1, DB::table('audit_logs')->where('action', 'BUILDING.BUILD')->count(), '超长 X-Request-ID 把建造审计冲掉了');
    }

    // 合法长度的客户端 ID 仍要透传(链路追踪不能被上面的加固误伤)
    public function test_valid_length_request_id_is_still_honoured(): void
    {
        $u = User::create(['username' => 'ridok', 'name' => 'ridok', 'email' => 'rid3@x.com', 'password' => 'password123']);
        CityFactory::createForUser($u);

        $id = str_repeat('b', 36);
        $this->actingAs($u)->getJson('/api/admin/settings', ['X-Request-ID' => $id])->assertStatus(403);

        $this->assertSame($id, DB::table('audit_logs')
            ->where('action', 'SECURITY.AUTHORIZATION_FAILED')->value('request_id'));
    }

    // ---------- Security Log allowlist(CLAUDE §61) ----------

    // 只有白名单字段能进 security 通道;密码/Session/Token/Cookie 之类一律丢弃,
    // 而不是「先记录再脱敏」
    public function test_security_logger_drops_non_allowlisted_context(): void
    {
        $captured = null;
        Log::shouldReceive('channel')->with('security')->andReturn($fake = \Mockery::mock());
        $fake->shouldReceive('warning')->once()->andReturnUsing(function ($event, $context) use (&$captured) {
            $captured = $context;
        });

        SecurityLogger::log('security.allowlist_probe', [
            'user_id'       => 7,
            'reason'        => 'NOT_OWNER',
            'password'      => 'hunter2',
            'session_id'    => 'sess-abcdef',
            'csrf_token'    => 'tok-abcdef',
            'authorization' => 'Bearer abcdef',
            'cookie'        => 'laravel_session=abcdef',
            'request_body'  => ['password' => 'hunter2'],
        ]);

        $this->assertNotNull($captured);
        $this->assertSame(['reason'], array_values(array_diff(array_keys($captured), ['request_id', 'ip', 'user_id'])));
        $this->assertSame(7, $captured['user_id']);
        $this->assertStringNotContainsString('hunter2', json_encode($captured));
        $this->assertStringNotContainsString('abcdef', json_encode($captured));
    }

    // ---------- Security Log 口径:同一次越权只记一条 ----------

    // C6 遗留:WorkerService 在抛 FORBIDDEN 之前自己写过一条 security.authorization_failed,
    // 而全局 render 见到 FORBIDDEN 还会再补一条 —— 同一次越权在 security 通道里出现两遍,
    // 「短时间内多少次越权」这类异常检测阈值直接被这条路径带偏一倍。
    //
    // 统一口径:走全局 render 的(抛 GameRuleException)由 render 写;
    // 直接 return 响应、不经 render 的(DemolishController)自己写。无论哪条路径,都恰好一条。
    public function test_authorization_failure_logs_exactly_one_security_event(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $victim = User::create(['username' => 'sl_victim', 'name' => 'sl_victim', 'email' => 'slv@x.com', 'password' => 'password123']);
        $victimCity = CityFactory::createForUser($victim);
        $instance = CityBuildingInstance::create([
            'city_id' => $victimCity->id, 'building_id' => 'F02', 'level' => 1,
            'x' => 3, 'y' => 3, 'status' => 'active', 'assigned_workers' => 0,
        ])->id;

        $attacker = User::create(['username' => 'sl_attacker', 'name' => 'sl_attacker', 'email' => 'sla@x.com', 'password' => 'password123']);
        CityFactory::createForUser($attacker);

        // 直接换掉 security 通道的 Monolog handler(不 mock Log 门面,免得连带吃掉其它通道的调用)
        $handler = new \Monolog\Handler\TestHandler();
        Log::channel('security')->getLogger()->setHandlers([$handler]);

        $routes = [
            ['/api/city/workers/assign', ['instance_id' => $instance, 'workers' => 1]], // 抛异常 → render 写
            ['/api/city/upgrade',        ['instance_id' => $instance]],                 // 抛异常 → render 写
            ['/api/city/demolish',       ['instance_id' => $instance]],                 // 直接 return → 自己写
        ];

        foreach ($routes as [$route, $payload]) {
            $handler->clear();

            $this->actingAs($attacker)->postJson($route, $payload)->assertStatus(403);

            $events = array_values(array_filter(
                $handler->getRecords(),
                fn ($r) => (string) $r->message === 'security.authorization_failed'
            ));
            $this->assertCount(1, $events, "{$route} 的 security.authorization_failed 不是恰好一条(双记会带偏异常检测阈值)");
        }
    }

    // ---------- 审计口径:成功的 M2 mutation 恰好一条,字段齐全 ----------

    public function test_each_successful_m2_mutation_writes_exactly_one_audit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        $u = User::create(['username' => 'auditor', 'name' => 'auditor', 'email' => 'au@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('cities')->where('id', $city->id)->update(['era_key' => 'II', 'era_order' => 2, 'money' => 5000]);
        foreach (['wood' => 800, 'stone' => 800, 'food' => 500, 'knowledge' => 500] as $res => $amount) {
            DB::table('city_resources')->updateOrInsert(['city_id' => $city->id, 'resource_id' => $res], ['amount' => $amount]);
        }
        $this->unlockTech($city->id, 'TECH_I_SUST');

        $farm = CityBuildingInstance::create([
            'city_id' => $city->id, 'building_id' => 'F02', 'level' => 1,
            'x' => 1, 'y' => 1, 'status' => 'active', 'assigned_workers' => 0,
        ])->id;

        $calls = [
            ['WORKER.ASSIGN',           '/api/city/workers/assign', ['instance_id' => $farm, 'workers' => 4]],
            ['TECH.RESEARCH_START',     '/api/city/research',       ['tech_id' => 'TECH_II_SUST']],
            ['BUILDING.UPGRADE',        '/api/city/upgrade',        ['instance_id' => $farm]],
            ['BUILDING.UPGRADE_CANCEL', '/api/city/upgrade/cancel', ['instance_id' => $farm]],
            ['BUILDING.DEMOLISH',       '/api/city/demolish',       ['instance_id' => $farm]],
        ];

        foreach ($calls as [$action, $route, $payload]) {
            $revisionBefore = (int) DB::table('cities')->where('id', $city->id)->value('revision');

            $this->actingAs($u)->postJson($route, $payload)->assertOk();

            $rows = DB::table('audit_logs')->where('action', $action)->get();
            $this->assertCount(1, $rows, "{$action} 的审计条数不是 1");
            $row = $rows[0];

            $this->assertSame('success', $row->status);
            $this->assertSame((int) $u->id, (int) $row->user_id);
            $this->assertSame((int) $city->id, (int) $row->city_id);
            $this->assertSame($revisionBefore, (int) $row->city_revision_before, "{$action} 的 revision_before 不对");
            $this->assertSame($revisionBefore + 1, (int) $row->city_revision_after, "{$action} 的 revision_after 不对");
            $this->assertNotNull($row->request_id);
            $this->assertNotNull($row->game_data_version, "{$action} 没记数值版本(§65)");
            // 经济类必须带 delta;工人分配也带(assigned 的增减)
            $this->assertNotNull($row->delta_json, "{$action} 缺 delta_json");
            // 审计里绝不能出现凭证类字段
            $blob = implode('|', [(string) $row->before_json, (string) $row->after_json, (string) $row->delta_json, (string) $row->metadata_json]);
            foreach (['password', 'session', 'csrf', 'token', 'secret'] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $blob, "{$action} 的审计里出现了敏感字段:{$needle}");
            }
        }
    }
}
