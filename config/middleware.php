<?php
/**
 * This file is part of webman.
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

return [
    'adminapi' => [
        \app\middleware\RateLimiterMiddleware::class,
    ],
    'api'      => [
        \app\api\middleware\MemberActivityMiddleware::class,
    ],
    '@'        => [
        \app\middleware\CheckInstallMiddleware::class,
        \app\middleware\AllowCrossOriginMiddleware::class,
        \app\middleware\Lang::class,
        // 会员活跃心跳须挂全局：portal 插件路由 app 未命名且走独立插件中间件空间，
        // 'api'/'' 分组均无法覆盖；中间件内对无 token / 非 api client 的请求直接短路
        \app\api\middleware\MemberActivityMiddleware::class,
    ],
    ''         => [],
];
