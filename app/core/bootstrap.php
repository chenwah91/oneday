<?php
// 应用引导:按顺序加载核心与业务文件(不用 Composer 自动加载)
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/error_text.php';
require_once __DIR__ . '/auth.php';
// Task 5 创建后取消注释:
// require_once dirname(__DIR__) . '/services/auth_service.php';
App::config();
