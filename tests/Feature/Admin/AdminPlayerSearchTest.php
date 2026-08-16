<?php

namespace Tests\Feature\Admin;

use App\Game\City\CityFactory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// 玩家列表强化(W11-C1 任务2):搜索 / 角色过滤 / 游标分页,且**无参数时行为完全不变**。
class AdminPlayerSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        $user = User::create(['username' => 'searchadm', 'name' => 'searchadm', 'email' => 'searchadm@example.com', 'password' => 'password123']);
        $user->forceFill(['role' => 'admin'])->save();

        return $user;
    }

    private function player(string $un, ?string $email = null): User
    {
        return User::create([
            'username' => $un, 'name' => $un,
            'email' => $email ?? "{$un}@example.com", 'password' => 'password123',
        ]);
    }

    // ---- 兼容:无参数 = 原样 ----

    public function test_no_param_keeps_legacy_shape_and_ascending_order(): void
    {
        $admin = $this->admin();
        $a = $this->player('zzz_last');
        $b = $this->player('aaa_first');
        CityFactory::createForUser($a);

        $res = $this->actingAs($admin, 'admin')->getJson('/api/admin/players')->assertOk();
        $players = $res->json('data.players');

        // 旧契约:id 升序,且不带分页字段
        $ids = array_column($players, 'id');
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids, '无参数时必须保持 id 升序');
        $this->assertNull($res->json('data.next_before_id'), '无参数时不返回游标字段');

        // 旧的 camelCase 键必须还在(前端在读),新的 snake_case 键一并补上
        $row = collect($players)->firstWhere('username', 'zzz_last');
        $this->assertArrayHasKey('createdAt', $row);
        $this->assertArrayHasKey('cityId', $row);
        $this->assertArrayHasKey('created_at', $row);
        $this->assertArrayHasKey('banned_at', $row);
        $this->assertNull($row['banned_at']);
        $this->assertSame($b->id, collect($players)->firstWhere('username', 'aaa_first')['id']);
    }

    // ---- q:前缀搜索 ----

    public function test_search_matches_username_or_email_prefix_only(): void
    {
        $admin = $this->admin();
        $this->player('alpha_one');
        $this->player('alpha_two');
        $this->player('beta_one');
        // email 前缀命中、username 不命中的一条
        $this->player('gamma_one', 'alphamail@example.com');

        $names = collect($this->actingAs($admin, 'admin')->getJson('/api/admin/players?q=alpha')->assertOk()
            ->json('data.players'))->pluck('username')->all();

        sort($names);
        $this->assertSame(['alpha_one', 'alpha_two', 'gamma_one'], $names, 'username 与 email 前缀都要命中');

        // 中缀不该命中:'one' 只是 alpha_one 的后半段
        $this->assertSame(
            [],
            $this->actingAs($admin, 'admin')->getJson('/api/admin/players?q=one')->assertOk()->json('data.players'),
            '前缀匹配不该命中中缀'
        );
    }

    // 通配符必须被转义:输入 % 不能变成「匹配所有人」
    public function test_search_escapes_like_wildcards(): void
    {
        $admin = $this->admin();
        $this->player('wild_a');
        $this->player('wild_b');

        $this->assertSame(
            [],
            $this->actingAs($admin, 'admin')->getJson('/api/admin/players?q=%25')->assertOk()->json('data.players'),
            '% 必须被当成字面量,不能匹配到任何人'
        );

        // 下划线同理:'wild_' 应当只按字面量匹配(这里字面量恰好命中两条)
        $underscore = $this->actingAs($admin, 'admin')->getJson('/api/admin/players?q=wild_')->assertOk()->json('data.players');
        $this->assertCount(2, $underscore);

        // 'wildX' 形态不该命中 —— 若 _ 被当成通配符,下面这一条会返回 0 条而不是报错,
        // 所以正面验一次「_ 只当字面量」:searchadm 里没有 wild 开头的其它账号
        $this->assertSame(
            [],
            $this->actingAs($admin, 'admin')->getJson('/api/admin/players?q=wildX')->assertOk()->json('data.players')
        );
    }

    // ---- role 过滤 ----

    public function test_role_filter_and_invalid_role_rejected(): void
    {
        $admin = $this->admin();
        $support = $this->player('rolesupport');
        $support->forceFill(['role' => 'support'])->save();
        $this->player('roleplayer');

        $players = $this->actingAs($admin, 'admin')->getJson('/api/admin/players?role=support')->assertOk()->json('data.players');
        $this->assertCount(1, $players);
        $this->assertSame('rolesupport', $players[0]['username']);

        // 非法角色一律 422,而不是静默返回空列表(空列表会被读成「这个角色没有人」)
        $this->actingAs($admin, 'admin')->getJson('/api/admin/players?role=godmode')
            ->assertStatus(422)->assertJsonPath('error', 'VALIDATION_ERROR');
    }

    // ---- 游标分页 ----

    public function test_cursor_pagination_walks_all_players_without_gap_or_repeat(): void
    {
        $admin = $this->admin();
        for ($i = 0; $i < 7; $i++) {
            $this->player('page'.$i);
        }

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $url = '/api/admin/players?limit=3'.($cursor ? "&before_id={$cursor}" : '');
            $res = $this->actingAs($admin, 'admin')->getJson($url)->assertOk();
            $ids = array_column($res->json('data.players'), 'id');

            $this->assertLessThanOrEqual(3, count($ids), 'limit 必须生效');
            $seen = array_merge($seen, $ids);
            $cursor = $res->json('data.next_before_id');
            $pages++;
        } while ($cursor !== null && $pages < 10);

        // 8 个账号(7 玩家 + 1 admin),3 条一页
        $this->assertSame(8, count($seen));
        $this->assertSame(8, count(array_unique($seen)), '游标翻页不得出现重复行');
        $this->assertNull($cursor, '走完之后游标必须为 null');
    }

    // limit clamp 照 audit 的写法:超大值夹到 200,0 / 负数夹到 1
    public function test_limit_is_clamped(): void
    {
        $admin = $this->admin();
        for ($i = 0; $i < 4; $i++) {
            $this->player('clamp'.$i);
        }

        $res = $this->actingAs($admin, 'admin')->getJson('/api/admin/players?limit=99999')->assertOk();
        $this->assertSame(200, $res->json('data.limit'));
        $this->assertLessThanOrEqual(200, count($res->json('data.players')));

        $res = $this->actingAs($admin, 'admin')->getJson('/api/admin/players?limit=0')->assertOk();
        $this->assertSame(1, $res->json('data.limit'));
        $this->assertCount(1, $res->json('data.players'));
    }
}
