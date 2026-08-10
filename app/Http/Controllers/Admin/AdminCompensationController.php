<?php

namespace App\Http\Controllers\Admin;

use App\Game\Admin\CompensationService;
use App\Game\Resource\ResourceCode;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Support\ApiResponse;
use App\Support\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 管理员补偿(CLAUDE §80 / E7):按用户名或 city_id 定位城市 → 查当前余额 → 补偿/扣减。
// 权限 adjust_resource(game_master 及以上),真正的拦截在 EnsureAdmin 中间件。
// 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)。
class AdminCompensationController extends Controller
{
    // 查:按 username 或 city_id 定位玩家城市,返回当前余额与可补偿资源清单(带中文显示名)。
    //
    // 只读、不触发结算:GET 必须是安全方法,不该顺手改玩家存档。
    // 因此这里给出的是「上次结算时」的余额;真正的补偿会在锁内重新结算后按最新值计算,
    // 补偿响应里的 before/after 才是权威值。
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['nullable', 'string', 'max:190'],
            'city_id'  => ['nullable', 'integer', 'min:1'],
        ]);

        $city = self::resolveCity($data);
        if ($city === null) {
            return ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        }

        // 仅取展示所需字段,password/remember_token 等敏感列不进内存
        $user = DB::table('users')->where('id', $city->user_id)->select('id', 'username', 'role')->first();

        // 当前库存:没有行的资源按 0 显示,方便直接对某个「还没持有过」的资源做补偿
        $amounts = DB::table('city_resources')->where('city_id', $city->id)
            ->pluck('amount', 'resource_id')->map(fn ($a) => (float) $a)->all();

        // 显示名以 resource_definition 为准(§13 数据驱动),缺行时回退到 ResourceCode 的中文表
        $names = DB::table('resource_definition')->pluck('name', 'resource_id')->all();

        $resources = [];
        foreach (ResourceCode::CHINESE_NAMES as $code => $chinese) {
            if (! CompensationService::isAdjustable($code)) {
                continue;
            }
            $resources[] = [
                'code'   => $code,
                'name'   => $names[$code] ?? $chinese,
                'amount' => $code === ResourceCode::MONEY ? (float) $city->money : ($amounts[$code] ?? 0.0),
            ];
        }

        return ApiResponse::ok(['data' => [
            'user' => [
                'id'       => (int) $user->id,
                'username' => $user->username,
                'role'     => $user->role,
            ],
            'city' => [
                'id'                => (int) $city->id,
                'name'              => $city->name,
                'revision'          => (int) $city->revision,
                'population'        => (int) $city->population,
                'money'             => (float) $city->money,
                'last_simulated_at' => (string) $city->last_simulated_at,
            ],
            'resources' => $resources,
        ]]);
    }

    // 补偿 / 扣减:一次只动一种资源。业务规则一律由 CompensationService 在锁内判定(CLAUDE §45)
    public function compensate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username'        => ['nullable', 'string', 'max:190'],
            'city_id'         => ['nullable', 'integer', 'min:1'],
            'resource'        => ['required', 'string', 'max:32'],
            // delta 允许负数(扣减);上下界只做防手滑护栏,结果是否合法在服务层锁内判
            'delta'           => ['required', 'numeric', 'between:-1000000000,1000000000'],
            // reason 至少 5 字(§63 强制填原因,「abc」这种等于没填);
            // 上限 80 对齐 audit_logs.reason_code 列宽,超长会导致写审计失败、事务回滚且不留痕
            'reason'          => ['required', 'string', 'min:5', 'max:80'],
            'ticket'          => ['nullable', 'string', 'max:64'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        $city = self::resolveCity($data);
        if ($city === null) {
            return ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        }

        $ticket = isset($data['ticket']) ? trim((string) $data['ticket']) : '';

        $result = CompensationService::apply(
            $request->user(),
            $city,
            (string) $data['resource'],
            (float) $data['delta'],
            (string) $data['reason'],
            $ticket !== '' ? $ticket : null,
            $data['idempotency_key'] ?? null
        );

        return ApiResponse::ok(['data' => $result]);
    }

    // 定位目标城市:city_id 优先(更精确),否则按用户名找该玩家的城市。
    // 两者都没给等同于「没指定目标」,按未找到处理 —— 绝不退化成「随便挑一座城」
    private static function resolveCity(array $data): ?City
    {
        if (isset($data['city_id'])) {
            return City::find((int) $data['city_id']);
        }

        $username = isset($data['username']) ? trim((string) $data['username']) : '';
        if ($username === '') {
            return null;
        }

        $userId = DB::table('users')->where('username', $username)->value('id');
        if ($userId === null) {
            return null;
        }

        return City::where('user_id', $userId)->first();
    }
}
