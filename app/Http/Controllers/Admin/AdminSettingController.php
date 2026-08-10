<?php

namespace App\Http\Controllers\Admin;

use App\Game\Resource\ResourceCode;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\ErrorCode;
use App\Support\GameSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// 后台规则开关(game_settings):列出 / 修改。
// 权限 edit_definition(admin 及以上):开关会改变全服规则,与改数值同级,game_master 不碰。
// 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)。
class AdminSettingController extends Controller
{
    // 列表:key / 说明 / 当前值 / 默认值 / 类型 / 最后修改人与时间
    public function index(): JsonResponse
    {
        return ApiResponse::ok(['data' => ['settings' => GameSetting::all()]]);
    }

    // 修改:allowlist(只认 GameSetting::DEFINITIONS 里登记过的 key)+ 强制 reason + 审计
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'setting_key' => ['required', 'string', 'max:64'],
            // value 的类型由 GameSetting 按登记的 type 校验(布尔开关只收真正的 true/false),
            // 这里不能写 'boolean' 规则:将来加数值型开关时不必再改这一行
            'value'       => ['required'],
            // reason 上限 80 对齐 audit_logs.reason_code 列宽(与 Definition 调整同口径)
            'reason'      => ['required', 'string', 'min:2', 'max:80'],
        ]);

        if (! isset(GameSetting::DEFINITIONS[$data['setting_key']])) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['setting_key' => ['未登记的设定项,不可修改']],
            ]);
        }

        // 对象型设定(如 initial_resources)先在这里逐键校验一次,只为把「哪一项填错了」告诉运营。
        // 真正的把关仍在 GameSetting::castValue —— 服务层是唯一权威,这里漏判也不会写进脏配置
        if (GameSetting::DEFINITIONS[$data['setting_key']]['type'] === GameSetting::TYPE_RESOURCE_MAP) {
            $errors = self::resourceMapErrors($data['value']);
            if ($errors) {
                return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, ['errors' => $errors]);
            }
        }

        // 类型不符会由 GameSetting::set 抛 GameRuleException(422),交全局 render 统一转响应
        $result = GameSetting::set(
            (string) $data['setting_key'],
            $data['value'],
            (int) $request->user()->id,
            (string) $data['reason']
        );

        return ApiResponse::ok(['data' => [
            'setting_key' => (string) $data['setting_key'],
            'before'      => $result['before'],
            'after'       => $result['after'],
        ]]);
    }

    // 对象型设定的逐项错误信息(键 = 表单字段路径,便于后台把红字标到具体那一行)。
    // 规则与 GameSetting::normalizeResourceMap 一一对应,只是这里附带中文说明
    private static function resourceMapErrors(mixed $value): array
    {
        if (! is_array($value) || $value === [] || array_is_list($value)) {
            return ['value' => ['必须是「资源 code → 数量」的对象,且至少一项']];
        }

        $errors = [];
        foreach ($value as $code => $amount) {
            $code = (string) $code;
            $field = 'value.' . $code;

            if (! isset(ResourceCode::CHINESE_NAMES[$code]) || ResourceCode::isCapacity($code)) {
                $errors[$field] = ['不是合法的库存资源 code'];
                continue;
            }
            if ((! is_int($amount) && ! is_float($amount)) || (is_float($amount) && ! is_finite($amount))) {
                $errors[$field] = ['数量必须是数字(不接受字符串 / 布尔 / 嵌套对象)'];
                continue;
            }
            if ($amount < 0 || $amount > GameSetting::MAX_RESOURCE_AMOUNT) {
                $errors[$field] = ['数量必须在 0 ~ ' . GameSetting::MAX_RESOURCE_AMOUNT . ' 之间'];
            }
        }

        return $errors;
    }
}
