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

namespace app\api\middleware;

use app\model\member\Member;
use core\infrastructure\cache\CacheService;
use core\security\jwt\enum\ClientType;
use core\security\jwt\enum\TokenType;
use core\security\jwt\JwtToken;
use support\Container;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 会员活跃心跳中间件（全局）
 *
 * 携带有效会员 access_token（client=api）的任意请求都会节流更新
 * member.last_active_time，活跃用户判定不再依赖 last_login_time。
 * 每用户 5 分钟最多一次 UPDATE（Redis SETNX 节流），异常静默不影响业务。
 */
final class MemberActivityMiddleware implements MiddlewareInterface
{

    public function process(Request $request, callable $handler): Response
    {
        $this->touchActivity();
        return $handler($request);
    }

    /**
     * 节流写活跃心跳
     */
    private function touchActivity(): void
    {
        try {
            // 短路 1：无 Authorization 头（未登录/静态资源/跨域预检）直接返回，开销≈0
            $jwt   = new JwtToken();
            $token = $jwt->getAccessTokenFromRequest();
            if (empty($token)) {
                return;
            }
            $payload = $jwt->getPayloadFromRequest();
            if (empty($payload)
                || ($payload['type'] ?? '') !== TokenType::ACCESS->value
                || ($payload['client'] ?? '') !== ClientType::API->value
                || empty($payload['id'])) {
                return;
            }

            $cache   = Container::make(CacheService::class);
            $lockKey = 'member:activity:touch:' . $payload['id'];
            // setLock 为 SETNX+TTL：命中锁（5 分钟内已记录）则跳过，避免高频写库
            if ($cache->setLock($lockKey, 300)) {
                Member::query()->whereKey($payload['id'])->update(['last_active_time' => time()]);
            }
        } catch (\Throwable $e) {
            // 心跳失败不影响正常业务请求
        }
    }

}
