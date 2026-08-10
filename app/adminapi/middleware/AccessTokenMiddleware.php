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
namespace app\adminapi\middleware;

use app\adminapi\middleware\helper\SseHelper;
use app\middleware\traits\PlaygroundTrait;
use core\foundation\exception\handler\UnauthorizedHttpException;
use core\security\jwt\JwtToken;
use core\foundation\tool\Json;
use madong\swagger\attribute\AllowAnonymous;
use madong\swagger\helper\AnnotationHelper;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * AccessToken 中间件（JWT Token 验证）
 * 功能：
 * 1. 检查请求是否需要跳过 Token 验证
 * 2. 验证 JWT Token 有效性
 */
#[\Attribute]
final class AccessTokenMiddleware implements MiddlewareInterface
{
    use PlaygroundTrait;

    public function process(Request $request, callable $handler): Response
    {
        $route = $request->route;
        if (!$route || !isset($request->action)) {
            return $handler($request);
        }

        $controllerClass = $request->controller;
        $action          = $request->action;

        $skipAuth = AnnotationHelper::getMethodAnnotation($controllerClass, $action, AllowAnonymous::class);
        if ($skipAuth && !$skipAuth->requireToken) {
            return $handler($request);
        }

        try {
            $jwt    = new JwtToken();
            $userId = $jwt->id();
            if (empty($userId)) {
                throw new UnauthorizedHttpException();
            }

            // Playground 环境：按路由规则拦截（命中则抛异常，由下面 catch 统一处理）
            $this->checkPlaygroundRestriction($request);

            // 从 JWT payload 中提取用户扩展信息
            $payload = $jwt->getPayloadFromRequest();
            $ext     = $payload['extra'] ?? $payload;
        } catch (\Exception $e) {
            if (SseHelper::isSseRequest($request)) {
                return SseHelper::sendSseErrorViaConnection($request, $e->getMessage());
            }
            // Playground 限制不设 HTTP 401，其余异常保持 401
            $code = $e instanceof \RuntimeException ? -1 : 401;
            return Json::fail($e->getMessage(), [], $code);
        }
        return $handler($request);
    }
}
