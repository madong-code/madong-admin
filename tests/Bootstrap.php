<?php
declare(strict_types=1);

/**
 * PHPUnit 测试引导文件
 * phpunit.xml 中 bootstrap="tests/Bootstrap.php"
 *
 * 两种运行模式：
 *   1. 单元测试（默认）— 只加载 autoload，不引导 Webman 容器
 *   2. 集成测试 — 需设置环境变量 WFM_INTEGRATION=1，会引导完整 Webman 配置/路由/中间件
 */

// Composer 自动加载
// 在 autoload 前降低 error_reporting，抑制 vendor 层 (qiniu/php-sdk 等) 的 Deprecated 噪声
// PHP 8.2+ 对 implicit nullable 更严格，但这些第三方包升级成本较高
$oldErrorReporting = error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__ . '/../vendor/autoload.php';

// 恢复完整错误级别（测试代码自身不应该有 deprecated）
error_reporting($oldErrorReporting);

// 测试环境标记
defined('TESTING') || define('TESTING', true);
defined('APP_DEBUG') || define('APP_DEBUG', true);

// 集成测试模式下才引导 Webman 容器
if (!empty($_ENV['WFM_INTEGRATION']) || !empty(getenv('WFM_INTEGRATION'))) {
    $config = require __DIR__ . '/../support/bootstrap.php';
    // Webman\Application 在 Worker 环境外不可用，集成测试通过直接 require bootstrap 访问配置
}
