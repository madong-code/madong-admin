<?php
declare(strict_types=1);

/**
 *+------------------
 * madong
 *+------------------
 * Copyright (c) https://gitee.com/motion-code  All rights reserved.
 *+------------------
 * Author: Mr. April (405784684@qq.com)
 *+------------------
 * Official Website: http://www.madong.tech
 */

namespace core\communication\mcp\endpoint;

use core\communication\mcp\bridge\PsrRequestFactory;
use core\communication\mcp\bridge\ResponseEmitter;
use core\communication\mcp\discovery\ToolManifest;
use core\communication\mcp\security\Authenticator;
use core\communication\mcp\security\McpAuthException;
use core\communication\mcp\server\ServerFactory;
use core\security\jwt\ex\JwtException;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use support\Log;
use support\Request;
use support\Response;

/**
 * MCP 端点控制器（POST/GET/DELETE /mcp）
 *
 * 执行序：enable 检查 -> 双通道鉴权 -> 工具清单权限过滤 -> 逐请求构建 SDK Server
 * -> PSR-7 桥接 -> run -> 发射响应。
 * 不挂 adminapi 分组，仅受全局 @ 中间件约束；鉴权失败转 401，内部错误转 500，
 * 均不穿透 webman 全局异常处理。
 */
final class McpEndpointController
{
    public function handle(Request $request): Response
    {
        if (!config('mcp.enable', false)) {
            return new Response(404, [], 'Not Found');
        }

        $logger = self::logger();

        try {
            $user = (new Authenticator())->authenticate($request);
        } catch (McpAuthException | JwtException $e) {
            $logger->warning('MCP auth failed', ['channel' => $e::class, 'reason' => $e->getMessage()]);
            return self::errorResponse(401, $e->getMessage());
        }

        try {
            $manifest = new ToolManifest();
            $tools = $manifest->filterFor($manifest->load(), $user);

            $transport = new StreamableHttpTransport(
                PsrRequestFactory::create($request),
                new Psr17Factory(),
                new Psr17Factory(),
                $logger,
                // 关闭 SDK 默认 CORS/DNS 中间件，规避与全局 AllowCrossOriginMiddleware 叠加
                [],
            );
            $server = (new ServerFactory())->build($tools, $user, $logger);
            $psrResponse = $server->run($transport);
        } catch (\Throwable $e) {
            $logger->error('MCP endpoint error', ['error' => $e->getMessage(), 'exception' => $e::class]);
            return self::errorResponse(500, 'Internal error');
        }

        return (new ResponseEmitter())->emit($psrResponse);
    }

    private static function logger(): LoggerInterface
    {
        try {
            return Log::channel('default');
        } catch (\Throwable) {
            return new NullLogger();
        }
    }

    private static function errorResponse(int $status, string $message): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32000, 'message' => $message],
            ], JSON_UNESCAPED_UNICODE),
        );
    }
}
