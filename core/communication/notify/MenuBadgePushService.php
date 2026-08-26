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
namespace core\communication\notify;

use core\communication\notify\enum\PushClientType;
use core\infrastructure\logger\Logger;
use InvalidArgumentException;
use madong\helper\Arr;
use support\Log;
use Webman\Push\Api;

/**
 * 菜单徽标推送服务
 *
 * 用于通过 WebSocket 向指定用户推送菜单徽标更新事件。
 * 前端监听 'menu_badge' 事件，收到后更新徽标状态。
 *
 * 使用示例:
 *   $pushService = new MenuBadgePushService();
 *   $pushService->pushBadgeUpdate(1, '/workflow/approval/list', '5', 'primary');
 *   $pushService->pushBadgeBatchUpdate(1, [
 *       ['path' => '/workflow/approval/list', 'badge' => '5', 'badgeType' => 'normal', 'badgeVariants' => 'primary'],
 *       ['path' => '/order/list', 'badgeType' => 'dot', 'badgeVariants' => 'destructive'],
 *   ]);
 */
final class MenuBadgePushService
{
    private const BUSINESS_MODULE = 'admin';

    private Api $pushApi;

    public function __construct()
    {
        $config       = $this->getConfig();
        $this->pushApi = new Api(
            $config['api'],
            $config['app_key'],
            $config['app_secret']
        );
    }

    /**
     * 推送单个菜单徽标更新
     *
     * @param int|array $userIds    用户ID或用户ID数组
     * @param string    $path       菜单路径
     * @param string    $badge      徽标文本 (空字符串表示清除)
     * @param string    $variant    徽标颜色 (primary|success|warning|info|danger|destructive)
     * @param string    $type       徽标类型 (normal|dot)
     */
    public function pushBadgeUpdate(
        int|array $userIds,
        string $path,
        string $badge = '',
        string $variant = 'primary',
        string $type = 'normal'
    ): int {
        $userIds  = Arr::normalize($userIds);
        $channels = $this->buildChannels($userIds);

        $data = [
            'type' => 'update',
            'data' => [
                'path'           => $path,
                'badge'          => $badge,
                'badge_type'     => $type,
                'badge_variants' => $variant,
            ],
        ];

        try {
            $result = $this->pushApi->trigger($channels, 'menu_badge', $data);
            Log::info('[MenuBadgePush] 单条推送成功', [
                'channels' => $channels,
                'path'     => $path,
                'badge'    => $badge,
                'result'   => $result,
            ]);
            return is_int($result) ? $result : ($result ? 1 : 0);
        } catch (\Throwable $e) {
            Log::error('[MenuBadgePush] 单条推送失败: ' . $e->getMessage(), [
                'user_ids' => $userIds,
                'path'     => $path,
            ]);
            return 0;
        }
    }

    /**
     * 批量推送菜单徽标更新
     *
     * @param int|array $userIds 用户ID或用户ID数组
     * @param array     $updates 徽标数据数组
     */
    public function pushBadgeBatchUpdate(int|array $userIds, array $updates): int
    {
        $userIds  = Arr::normalize($userIds);
        $channels = $this->buildChannels($userIds);

        $data = [
            'type' => 'batch_update',
            'data' => $updates,
        ];

        try {
            $result = $this->pushApi->trigger($channels, 'menu_badge', $data);
            Logger::debug('菜单徽标批量推送成功', [
                'channels' => $channels,
                'count'    => count($updates),
            ]);
            Log::info('[MenuBadgePush] 批量推送成功', [
                'channels' => $channels,
                'count'    => count($updates),
                'result'   => $result,
            ]);
            return is_int($result) ? $result : ($result ? 1 : 0);
        } catch (\Throwable $e) {
            Logger::error('菜单徽标批量推送失败: ' . $e->getMessage(), [
                'exception' => $e,
                'user_ids'  => $userIds,
            ]);
            Log::error('[MenuBadgePush] 批量推送失败: ' . $e->getMessage(), [
                'user_ids' => $userIds,
                'channels' => $channels,
            ]);
            return 0;
        }
    }

    /**
     * 清除指定菜单的徽标
     *
     * @param int|array $userIds 用户ID或用户ID数组
     * @param string    $path    菜单路径
     */
    public function pushBadgeClear(int|array $userIds, string $path): int
    {
        $userIds  = Arr::normalize($userIds);
        $channels = $this->buildChannels($userIds);

        $data = [
            'type' => 'clear',
            'data' => ['path' => $path],
        ];

        try {
            $result = $this->pushApi->trigger($channels, 'menu_badge', $data);
            Log::info('[MenuBadgePush] 清除推送成功', [
                'channels' => $channels,
                'path'     => $path,
                'result'   => $result,
            ]);
            return is_int($result) ? $result : ($result ? 1 : 0);
        } catch (\Throwable $e) {
            Log::error('[MenuBadgePush] 清除推送失败: ' . $e->getMessage(), [
                'user_ids' => $userIds,
                'path'     => $path,
            ]);
            return 0;
        }
    }

    /**
     * 重置用户所有菜单徽标
     *
     * @param int|array $userIds 用户ID或用户ID数组
     */
    public function pushBadgeReset(int|array $userIds): int
    {
        $userIds  = Arr::normalize($userIds);
        $channels = $this->buildChannels($userIds);

        $data = [
            'type' => 'reset',
            'data' => [],
        ];

        try {
            $result = $this->pushApi->trigger($channels, 'menu_badge', $data);
            Log::info('[MenuBadgePush] 重置推送成功', [
                'channels' => $channels,
                'result'   => $result,
            ]);
            return is_int($result) ? $result : ($result ? 1 : 0);
        } catch (\Throwable $e) {
            Log::error('[MenuBadgePush] 重置推送失败: ' . $e->getMessage(), [
                'user_ids' => $userIds,
            ]);
            return 0;
        }
    }

    /**
     * 构建用户频道列表
     *
     * @param array $userIds 用户ID数组
     * @return array
     */
    private function buildChannels(array $userIds): array
    {
        return array_map(function ($userId) {
            return implode('-', [
                PushClientType::BACKEND->value,
                self::BUSINESS_MODULE,
                $userId,
            ]);
        }, $userIds);
    }

    /**
     * 获取推送配置
     *
     * @return array
     */
    private function getConfig(): array
    {
        $config = config('core.communication.notify.webman-push');
        if (empty($config)) {
            throw new \RuntimeException('推送配置未定义');
        }
        return $config;
    }
}
