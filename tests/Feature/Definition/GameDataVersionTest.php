<?php

namespace Tests\Feature\Definition;

use App\Game\City\CityFactory;
use App\Game\Definition\GameDataVersion;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// E10:Game Data Version 全链贯通(§64 / §65)
// 建城写版本 → 快照返回版本 + 服务器时间 → 审计带版本 → bump 落 checksum
class GameDataVersionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(); // 需要 Definition 定义表 + 初始 game_data_versions 记录
    }

    private function user(string $username): User
    {
        return User::create([
            'username' => $username, 'name' => $username,
            'email' => $username . '@example.com', 'password' => 'password123',
        ]);
    }

    private function admin(): User
    {
        // role 不可批量赋值,测试里用 forceFill 显式提权(与 AdminDefinitionTest 同款做法)
        $user = $this->user('gdvadmin');
        $user->forceFill(['role' => 'admin'])->save();

        return $user;
    }

    // 挑一条真实存在的建筑等级定义;不写死任何 ID,避免定义数据调整后测试脆断
    private function anyBuildingLevel(): object
    {
        return DB::table('building_level_definition')->orderBy('building_id')->orderBy('level')->first();
    }

    public function test_current_returns_latest_version(): void
    {
        $this->assertSame(
            DB::table('game_data_versions')->orderByDesc('id')->value('version'),
            GameDataVersion::current()
        );
    }

    // ---- 快照 ----

    public function test_snapshot_returns_data_version_and_server_time(): void
    {
        $user = $this->user('gdvsnap');
        CityFactory::createForUser($user);

        $res = $this->actingAs($user)->getJson('/api/city');
        $res->assertOk();
        $res->assertJsonStructure(['data' => ['dataVersion', 'serverTime', 'city']]);

        $body = $res->json('data');
        $this->assertSame(DB::table('game_data_versions')->orderByDesc('id')->value('version'), $body['dataVersion']);
        // ISO-8601,例:2026-08-10T12:34:56+08:00
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}$/', $body['serverTime']);
    }

    // 现有快照键一个都不能少(加字段不许顺手改契约)
    public function test_snapshot_keeps_existing_city_keys(): void
    {
        $user = $this->user('gdvkeys');
        CityFactory::createForUser($user);

        $this->actingAs($user)->getJson('/api/city')
            ->assertOk()
            ->assertJsonStructure(['data' => ['city' => [
                'id', 'name', 'revision', 'population', 'populationCapacity', 'money',
                'mapWidth', 'mapHeight', 'storageCapacity', 'lastSimulatedAt',
                'resources', 'ratesPerMin', 'buildings',
            ]]]);
    }

    // ---- 建城 ----

    public function test_city_creation_records_game_data_version(): void
    {
        $user = $this->user('gdvcity');
        $city = CityFactory::createForUser($user);

        $stored = DB::table('cities')->where('id', $city->id)->value('game_data_version');
        $this->assertNotNull($stored);
        $this->assertSame(DB::table('game_data_versions')->orderByDesc('id')->value('version'), $stored);
    }

    // ---- 审计 ----

    public function test_audit_row_carries_game_data_version(): void
    {
        // CITY.CREATE 是一条真实 Mutation 审计
        CityFactory::createForUser($this->user('gdvaudit'));

        $row = DB::table('audit_logs')->where('action', AuditAction::CITY_CREATE)->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame(DB::table('game_data_versions')->orderByDesc('id')->value('version'), $row->game_data_version);
    }

    // 后台改数值:先 bump 再写审计,审计行应带上「刚 bump 出来的新版本」
    public function test_admin_config_change_audit_carries_bumped_version(): void
    {
        $level = $this->anyBuildingLevel();

        $res = $this->actingAs($this->admin())->postJson('/api/admin/definitions/building-level', [
            'buildingId' => $level->building_id,
            'level'      => $level->level,
            'field'      => 'worker_required',
            'value'      => (int) $level->worker_required + 1,
            'reason'     => 'E10 版本贯通测试',
        ]);
        $res->assertOk();

        $newVersion = $res->json('data.version');
        $row = DB::table('audit_logs')->where('action', AuditAction::ADMIN_CONFIG_CHANGE)->latest('id')->first();
        $this->assertSame($newVersion, $row->game_data_version);
    }

    // 一次请求内写多条审计,只准查一次 game_data_versions(每请求缓存生效)
    public function test_audit_logger_reads_version_once_per_request(): void
    {
        $queries = 0;
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'game_data_versions')) {
                $queries++;
            }
        });

        for ($i = 0; $i < 3; $i++) {
            AuditLogger::record(AuditAction::AUTH_LOGIN_FAILED, 'failed', ['reason_code' => 'BAD_CREDENTIALS']);
        }

        $this->assertSame(3, DB::table('audit_logs')->where('action', AuditAction::AUTH_LOGIN_FAILED)->count());
        $this->assertSame(1, $queries, '一次请求内应只查一次 game_data_versions');
    }

    // ---- checksum ----

    public function test_bump_writes_checksum(): void
    {
        $version = GameDataVersion::bump('E10 测试', 'test');

        $checksum = DB::table('game_data_versions')->where('version', $version)->value('checksum');
        $this->assertNotNull($checksum);
        $this->assertSame(64, strlen($checksum));
    }

    // 内容没动 → 指纹必须稳定(否则无法用它判断「这一版数值到底变没变」)
    public function test_checksum_is_stable_when_definitions_unchanged(): void
    {
        $first = GameDataVersion::bump('E10 测试 A', 'test');
        $second = GameDataVersion::bump('E10 测试 B', 'test');

        $this->assertSame(
            DB::table('game_data_versions')->where('version', $first)->value('checksum'),
            DB::table('game_data_versions')->where('version', $second)->value('checksum')
        );
    }

    // 内容改了 → 指纹必须变
    public function test_checksum_changes_when_definitions_change(): void
    {
        $before = GameDataVersion::checksum();

        $level = $this->anyBuildingLevel();
        DB::table('building_level_definition')
            ->where('building_id', $level->building_id)->where('level', $level->level)
            ->update(['worker_required' => (int) $level->worker_required + 1]);

        $this->assertNotSame($before, GameDataVersion::checksum());
    }

    // bump 之后同一请求内再读,必须拿到新版本(缓存已失效)
    public function test_current_is_invalidated_after_bump(): void
    {
        $old = GameDataVersion::current();
        $new = GameDataVersion::bump('E10 缓存失效测试', 'test');

        $this->assertNotSame($old, $new);
        $this->assertSame($new, GameDataVersion::current());
    }
}
