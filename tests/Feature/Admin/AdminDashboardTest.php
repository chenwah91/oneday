<?php

namespace Tests\Feature\Admin;

use App\Game\City\CityFactory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 全服仪表盘(W11-C1 任务1):数字要对得上手工造的数据,而且**查询条数必须与规模无关**。
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(string $un = 'dashadm'): User
    {
        // role 已不可批量赋值,测试里用 forceFill 显式提权
        $user = User::create(['username' => $un, 'name' => $un, 'email' => "{$un}@example.com", 'password' => 'password123']);
        $user->forceFill(['role' => 'admin'])->save();

        return $user;
    }

    private function player(string $un): User
    {
        return User::create(['username' => $un, 'name' => $un, 'email' => "{$un}@example.com", 'password' => 'password123']);
    }

    // ---- 数字与手工造数一致 ----

    public function test_dashboard_numbers_match_hand_made_data(): void
    {
        $admin = $this->admin();

        // 三个玩家、三座城,资金分别设成好数记的值
        $money = [1000.00, 2500.50, 4000.00];
        $cityIds = [];
        foreach ($money as $i => $amount) {
            $p = $this->player('dashp'.$i);
            $city = CityFactory::createForUser($p);
            $cityIds[] = (int) $city->id;
            DB::table('cities')->where('id', $city->id)->update(['money' => $amount]);
        }

        // 只让第一座城「24 小时内活跃」,其余两座拨到 3 天前
        DB::table('cities')->whereIn('id', [$cityIds[1], $cityIds[2]])
            ->update(['last_simulated_at' => now()->subDays(3)]);

        // 资源:先清掉建城赠送的初始库存,再手工造三行 ——
        // 聚合后 wood 必须正好是两座城之和(留着初始库存就验不出「是不是真的在 GROUP BY」)
        DB::table('city_resources')->delete();
        DB::table('city_resources')->updateOrInsert(['city_id' => $cityIds[0], 'resource_id' => 'wood'], ['amount' => 100]);
        DB::table('city_resources')->updateOrInsert(['city_id' => $cityIds[1], 'resource_id' => 'wood'], ['amount' => 250]);
        DB::table('city_resources')->updateOrInsert(['city_id' => $cityIds[2], 'resource_id' => 'stone'], ['amount' => 70]);

        $res = $this->actingAs($admin)->getJson('/api/admin/dashboard')->assertOk();

        // 账号:3 个玩家 + 1 个 admin = 4;都是刚建的,今日新增同样是 4
        $this->assertSame(4, $res->json('data.players.total'));
        $this->assertSame(4, $res->json('data.players.new_today'));
        $this->assertSame(1, $res->json('data.players.staff'));
        $this->assertSame(0, $res->json('data.players.banned'));
        $this->assertSame(1, $res->json('data.players.active_24h'), '只有一座城的 last_simulated_at 在 24h 内');

        // 城市:3 座,资金总额 = 1000 + 2500.50 + 4000
        $this->assertSame(3, $res->json('data.cities.total'));
        $this->assertSame(7500.50, (float) $res->json('data.cities.money_total'));

        // 资源榜:wood 必须是两城之和(纯 GROUP BY 的直接体现)
        $top = collect($res->json('data.resources_top'));
        $wood = $top->firstWhere('resource_id', 'wood');
        $this->assertNotNull($wood, '资源榜里应有 wood');
        $this->assertSame(350.0, (float) $wood['total'], 'wood 必须是两座城 100 + 250 的聚合值');
        $this->assertLessThanOrEqual(10, $top->count(), '资源榜最多 10 条');

        // 建筑实例数:与直接 COUNT 一致(建城会送几栋,不写死数字)
        $this->assertSame(
            DB::table('city_building_instances')->count(),
            $res->json('data.buildings.total')
        );

        // 统计时间戳必须在(仪表盘会被截图,没有它分不清是数字没动还是页面没刷新)
        $this->assertNotEmpty($res->json('data.generated_at'));
        $this->assertNotEmpty($res->json('data.window.today_start'));
    }

    // 今日后台操作条数只数 ADMIN.*,不把玩家动作算进去
    public function test_dashboard_counts_admin_actions_today_only(): void
    {
        $admin = $this->admin();
        $target = $this->player('dashbanned');
        $before = $this->actingAs($admin)->getJson('/api/admin/dashboard')->json('data.audit.admin_actions_today');

        // 制造一条 ADMIN.PLAYER_BAN
        $this->actingAs($admin)->postJson("/api/admin/players/{$target->id}/ban", ['reason' => '仪表盘计数测试'])->assertOk();

        // 再制造一条**非** ADMIN 前缀的审计(登录失败),它不该被计入
        $this->postJson('/api/auth/login', ['username' => 'nobody-here', 'password' => 'wrongpass'])->assertStatus(401);

        $res = $this->actingAs($admin)->getJson('/api/admin/dashboard')->assertOk();

        $this->assertSame($before + 1, $res->json('data.audit.admin_actions_today'), '只应多出那一条 ADMIN.PLAYER_BAN');
        // 顺带验一下封禁计数确实进了仪表盘
        $this->assertSame(1, $res->json('data.players.banned'));
    }

    // ---- 零 N+1:查询条数与城市规模无关 ----

    public function test_dashboard_query_count_is_constant_regardless_of_city_count(): void
    {
        $admin = $this->admin();

        // ① 一座城时的查询条数
        $p = $this->player('dashn1');
        CityFactory::createForUser($p);
        $withOneCity = $this->countQueries(fn () => $this->actingAs($admin)->getJson('/api/admin/dashboard')->assertOk());

        // ② 再加 6 座城(每座都有自己的建筑与资源行)
        for ($i = 0; $i < 6; $i++) {
            CityFactory::createForUser($this->player('dashn'.$i.'x'));
        }
        $withSevenCities = $this->countQueries(fn () => $this->actingAs($admin)->getJson('/api/admin/dashboard')->assertOk());

        $this->assertSame(
            $withOneCity,
            $withSevenCities,
            "仪表盘出现了逐城查询:1 座城 {$withOneCity} 条,7 座城 {$withSevenCities} 条"
        );
        // 上限兜底:7 块数据 = 7 条聚合 SQL,留一点余量给框架自身的查询
        $this->assertLessThanOrEqual(12, $withSevenCities, '仪表盘的查询条数超出预期,检查是否混进了逐行查询');
    }

    // ---- 权限 ----

    public function test_dashboard_requires_read_player_permission(): void
    {
        // 未登录 401。**必须排在所有 actingAs 之前** ——
        // actingAs 会把用户挂在 guard 上并对本用例后续的每个请求生效
        $this->getJson('/api/admin/dashboard')->assertStatus(401);

        // 普通玩家连后台门槛都过不去
        $player = $this->player('dashdenied');
        $this->actingAs($player)->getJson('/api/admin/dashboard')->assertStatus(403);

        // support 有 read_player,应当放行
        $support = $this->player('dashsupport');
        $support->forceFill(['role' => 'support'])->save();
        $this->actingAs($support)->getJson('/api/admin/dashboard')->assertOk();
    }

    // DB::listen 计数:只统计闭包执行期间发出的 SQL
    private function countQueries(callable $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $callback();

        // 监听器无法注销,所以每个用例只调用一次;这里返回的就是本次请求的条数
        return $count;
    }
}
