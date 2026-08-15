<?php

namespace Tests\Feature\City;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefinitionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    public function test_lists_buildable_buildings(): void
    {
        $u = User::create(['username' => 'defviewer', 'name' => 'defviewer', 'email' => 'd@v.com', 'password' => 'password123']);
        $res = $this->actingAs($u)->getJson('/api/definitions/buildings');
        $res->assertOk();
        $res->assertJsonStructure(['data' => ['buildings' => [['building_id', 'name', 'footprint' => ['w', 'h'], 'level1' => ['cost']]]]]);
        // 94 座
        $this->assertCount(94, $res->json('data.buildings'));
    }

    // W16:建造面板要按 BuildService 的闸门顺序(时代 → 科技 → 数量上限)只开放当前可建的,
    // 所以定义端点必须下发前置科技与两个中文名 —— 少一个前端就只能按时代过滤,
    // 玩家会看到一堆点下去必然 TECH_NOT_UNLOCKED 的按钮
    public function test_buildings_expose_era_and_tech_gates(): void
    {
        $u = User::create(['username' => 'gateviewer', 'name' => 'gateviewer', 'email' => 'g@v.com', 'password' => 'password123']);
        $rows = $this->actingAs($u)->getJson('/api/definitions/buildings')->assertOk()->json('data.buildings');

        // 每一行都得有这四个键(tech_id / tech_name 允许为 null,但键必须在)
        foreach ($rows as $r) {
            $this->assertArrayHasKey('era_name', $r);
            $this->assertArrayHasKey('era_order', $r);
            $this->assertArrayHasKey('tech_id', $r);
            $this->assertArrayHasKey('tech_name', $r);
            $this->assertNotSame('', $r['era_name']);
        }

        // 有前置科技的建筑必须同时给出中文名(leftJoin 掉链子的话这里会红)
        $withTech = array_values(array_filter($rows, fn ($r) => $r['tech_id'] !== null && $r['tech_id'] !== ''));
        $this->assertNotEmpty($withTech, '定义里应当存在带前置科技的建筑');
        foreach ($withTech as $r) {
            $this->assertNotNull($r['tech_name'], $r['building_id'] . ' 的前置科技 ' . $r['tech_id'] . ' 没有对应的科技定义');
        }

        // 现行数值里 94 座**全部**带前置科技(新城市 0 科技 → 建造面板起手是空的,
        // 玩家必须先研究)。这条钉住这个前提:哪天有建筑不再需要科技,
        // 这里会红,提醒去复核建造面板的空态文案还准不准
        $this->assertCount(94, $withTech, '若有建筑不再需要前置科技,请复核 build-panel.js 的空态提示');
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/definitions/buildings')->assertStatus(401);
    }
}
