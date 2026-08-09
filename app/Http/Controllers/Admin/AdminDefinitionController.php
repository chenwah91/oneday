<?php

namespace App\Http\Controllers\Admin;

use App\Game\Definition\GameDataVersion;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 后台调整建筑等级数值:allowlist 字段 + 强制 reason + 审计 + 版本递增
class AdminDefinitionController extends Controller
{
    private const EDITABLE = [
        'duration_seconds', 'worker_required',
        'maintenance_money_per_min', 'maintenance_food_per_min', 'maintenance_fuel_per_min', 'power_per_min',
        'happiness_bonus', 'governance_bonus', 'defense_score', 'capacity',
    ];

    public function buildingLevels(Request $request): JsonResponse
    {
        $buildingId = (string) $request->query('buildingId', '');
        $rows = DB::table('building_level_definition')->where('building_id', $buildingId)->orderBy('level')
            ->get(array_merge(['building_id', 'level'], self::EDITABLE));
        return ApiResponse::ok(['data' => ['levels' => $rows]]);
    }

    public function editBuildingLevel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'buildingId' => ['required', 'string', 'max:16'],
            'level'      => ['required', 'integer', 'between:1,3'],
            'field'      => ['required', 'string'],
            'value'      => ['required', 'numeric'],
            'reason'     => ['required', 'string', 'min:2', 'max:200'],
        ]);

        if (! in_array($data['field'], self::EDITABLE, true)) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, ['errors' => ['field' => ['字段不可编辑']]]);
        }

        $admin = $request->user();

        $result = DB::transaction(function () use ($data, $admin) {
            $row = DB::table('building_level_definition')->where('building_id', $data['buildingId'])->where('level', $data['level'])->first();
            if (! $row) {
                return null;
            }
            $before = $row->{$data['field']};
            DB::table('building_level_definition')->where('building_id', $data['buildingId'])->where('level', $data['level'])
                ->update([$data['field'] => $data['value']]);

            $version = GameDataVersion::bump(
                "调整 {$data['buildingId']} L{$data['level']} {$data['field']}: {$before} → {$data['value']}",
                'admin:' . $admin->username
            );

            AuditLogger::record(AuditAction::ADMIN_CONFIG_CHANGE, 'success', [
                'actor_type' => 'admin', 'actor_id' => $admin->id, 'user_id' => $admin->id,
                'entity_type' => 'building_level_definition',
                'entity_id' => $data['buildingId'] . ':' . $data['level'],
                'reason_code' => $data['reason'],
                'before_json' => [$data['field'] => $before],
                'after_json' => [$data['field'] => $data['value']],
                'metadata_json' => ['game_data_version' => $version],
            ]);

            return ['before' => $before, 'after' => $data['value'], 'version' => $version];
        });

        if ($result === null) {
            return ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        }
        return ApiResponse::ok(['data' => $result]);
    }
}
