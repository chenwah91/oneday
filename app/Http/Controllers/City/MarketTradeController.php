<?php

namespace App\Http\Controllers\City;

use App\Game\City\CityFactory;
use App\Game\Market\TradeService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\GameSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// 市场买卖入口:校验意图 → TradeService → 统一响应。
// GameRuleException 不在此捕获,交由 bootstrap/app.php 的全局 render 统一转 ApiResponse(CLAUDE §78)。
//
// 入参**只有** resource_code + quantity(+ idempotency_key + expected_revision):
// 客户端不得提交成交价(§45 / §66 / v3.2 §8.1),所以这里根本没有接收价格的字段 ——
// 前端就算硬塞一个 price 进来,也会被 validate 的 allowlist 直接丢掉,不存在「被信任」的可能。
class MarketTradeController extends Controller
{
    public function buy(Request $request): JsonResponse
    {
        return $this->trade($request, TradeService::SIDE_BUY);
    }

    public function sell(Request $request): JsonResponse
    {
        return $this->trade($request, TradeService::SIDE_SELL);
    }

    private function trade(Request $request, string $side): JsonResponse
    {
        // 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)
        //
        // quantity 用 integer 而不是 numeric:市场只做整数份额的买卖。
        // Laravel 的 integer 规则底层是 FILTER_VALIDATE_INT,顺带挡掉 "3.5" / "abc" / 1.0e9(浮点)/
        // 科学计数法 —— §69 要求的「防止负数 / NaN / 超大数字」在这条 + min + max + 下面的严格类型闸里全覆盖。
        $data = $request->validate([
            'resource_code'     => ['required', 'string', 'max:32'],
            'quantity'          => [
                'required',
                // 严格类型闸,必须排在 integer 之前:FILTER_VALIDATE_INT 会把 JSON 的 true 解释成 1,
                // 于是 {"quantity": true} 会变成一笔「买 1 个」的真实成交。
                // 与 GameSetting 同一条纪律 —— 只收真正的数字,不做模糊解释:
                // 后台/客户端填错时要当场报错,而不是静默替玩家选一个值
                self::strictInteger(),
                'integer',
                'min:1',
                'max:' . (int) GameSetting::get(GameSetting::MARKET_MAX_ORDER_QUANTITY),
            ],
            'idempotency_key'   => ['nullable', 'string', 'max:100'],
            'expected_revision' => ['nullable', 'integer'],
        ]);

        $city = CityFactory::createForUser($request->user());

        $args = [
            $city,
            $data['resource_code'],
            (int) $data['quantity'],
            $data['idempotency_key'] ?? null,
            isset($data['expected_revision']) ? (int) $data['expected_revision'] : null,
        ];

        $diff = $side === TradeService::SIDE_BUY
            ? TradeService::buy(...$args)
            : TradeService::sell(...$args);

        return ApiResponse::ok(['data' => $diff]);
    }

    // 「只收真正的整数」闭包规则:接受 JSON 数字整数(is_int)与纯数字字符串("10",表单提交时一切都是字符串);
    // 拒绝 true / false / null / 数组 / 小数 / 带符号或空白的字符串。
    // 布尔是重点:PHP 的 filter_var(true, FILTER_VALIDATE_INT) 返回 1,不拦就是一笔真实成交
    private static function strictInteger(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            if (! is_int($value) && ! (is_string($value) && $value !== '' && ctype_digit($value))) {
                $fail('The ' . $attribute . ' field must be an integer.');
            }
        };
    }
}
