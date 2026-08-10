<?php

// 审计相关配置(M3-M.9 Hash Chain)。
//
// AUDIT_HMAC_SECRET(CLAUDE §75 Secrets):
//   - 只从环境变量读,绝不进数据库、绝不进 Git;.env.example 里只留字段名。
//   - 生产环境必须显式设置一个强随机值(见 docs/deploy.md 第 3 步);
//     一旦设定就不能再改 —— 改了以后旧行的 event_hash 全部对不上,verify 会整链报断。
//   - 本地开发 / 测试没设时,AuditChain 会从 APP_KEY 派生一个并打 warning 日志(见 AuditChain::secret)。
return [
    'hmac_secret' => env('AUDIT_HMAC_SECRET'),
];
