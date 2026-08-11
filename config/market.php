<?php

// 市场相关配置(M3-D3 定价内核)。
//
// MARKET_PRICE_SECRET(CLAUDE §75 Secrets / §30 服务器权威随机):
//   - 定价的波动扰动是 HMAC(secret, resource|epoch) 派生的**确定性伪随机**:
//     同一 epoch 任何时刻重算都得到同一价(服务器权威、无需 cron 落窗),
//     但客户端拿不到 secret,就算完整复刻公式也预测不了下一窗的价格。
//   - 因此这把密钥的泄露 = 玩家能提前知道所有未来价格 = 无风险套利。
//     只从环境变量读,绝不进数据库、绝不进 Git、绝不下发给前端;.env.example 里只留字段名。
//   - 生产必须显式设置强随机值(php -r "echo bin2hex(random_bytes(32));")。
//     改动它会让全市场价格瞬间跳变(历史成交流水不受影响),非必要不要改。
//   - 本地开发 / 测试没设时,PriceEngine 会从 APP_KEY 派生一个并打 warning(见 PriceEngine::secret)。
return [
    'price_secret' => env('MARKET_PRICE_SECRET'),
];
