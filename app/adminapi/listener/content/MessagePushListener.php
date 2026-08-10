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
namespace app\adminapi\listener\content;

use app\service\admin\content\message\NotifyService;
use core\communication\notify\NotificationService;
use core\foundation\base\BaseListener;
use core\infrastructure\logger\Logger;

class MessagePushListener extends BaseListener
{
    private ?NotificationService $notificationService = null;
    private ?NotifyService $notifyService = null;

    protected function process($event): void
    {
        Logger::info('消息推送事件', [
            'scene'  => $event->scene,
            'module' => $event->businessModule,
            'count'  => is_array($event->userIds) ? count($event->userIds) : 1,
        ]);

        try {
            if ($event->isForce) {
                $notificationService = $this->getNotificationService();
                $result = $notificationService->sendAndRecord(
                    $event->clientType,
                    $event->businessModule,
                    null,
                    $event->userIds,
                    $event->event,
                    $event->data,
                    $event->messageData,
                    $event->socketId
                );
            } else {
                $notifyService = $this->getNotifyService();
                $result  = $notifyService->send(
                    $event->clientType,
                    $event->businessModule,
                    null,
                    $event->userIds,
                    $event->event,
                    $event->data,
                    $event->messageData,
                    $event->socketId
                );
            }

            Logger::info('消息推送结果', [
                'push_count' => $result['push_count'] ?? 0,
                'scene'      => $event->scene,
            ]);
        } catch (\Throwable $e) {
            Logger::error('消息推送失败', [
                'error' => $e->getMessage(),
                'scene' => $event->scene,
            ]);
        }
    }

    private function getNotificationService(): NotificationService
    {
        if ($this->notificationService === null) {
            $this->notificationService = \support\Container::get(NotificationService::class);
        }
        return $this->notificationService;
    }

    private function getNotifyService(): NotifyService
    {
        if ($this->notifyService === null) {
            $this->notifyService = \support\Container::get(NotifyService::class);
        }
        return $this->notifyService;
    }
}