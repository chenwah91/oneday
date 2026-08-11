<?php

// 随机事件相关配置(M3-D4 触发引擎)。
//
// EVENT_SECRET(CLAUDE §75 Secrets / §30 服务器权威随机 / backlog §11.3):
//   - 事件的掷点是 HMAC(secret, city|window|label) 派生的**确定性伪随机**:
//     同一座城市、同一个资格窗口,任何时刻重算都掷出同一个结果。
//     §11.3 明文要求「离线补算的随机数不能依赖 now() 作种子(玩家可通过控制上线时间刷)」——
//     用 window_index + city_id + 服务端 secret 派生正是那一条的落地。
//   - 因此这把密钥泄露 = 玩家能提前算出自己每一个窗口会不会触发事件、会触发哪一个
//     = 可以精确挑时间上线刷正向事件。危害与 MARKET_PRICE_SECRET 同级。
//     只从环境变量读,绝不进数据库、绝不进 Git、绝不下发前端;.env.example 里只留字段名。
//   - 与市场刻意用**两把独立密钥**:一把泄露不会连带另一套系统全被预测,
//     也让将来单独轮换其中一把成为可能(轮换事件密钥只影响未来窗口的掷点,不影响任何历史实例)。
//   - 生产必须显式设置强随机值(php -r "echo bin2hex(random_bytes(32));")。
//   - 本地 / 测试没设时,EventRandom 会先尝试从 APP_KEY 派生并打 warning;
//     两个都没有时退回 CSPRNG(random_int)—— 牺牲可重算性,但绝不退化成
//     「永不触发」或「人人可预测」(见 EventRandom::unit 的 Fail Safe 说明)。
return [
    'secret' => env('EVENT_SECRET'),
];
