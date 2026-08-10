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

use app\model\content\message\Message;
use core\infrastructure\logger\Logger;
use core\communication\notify\enum\PushClientType;
use core\io\uuid\UUIDGenerator;
use InvalidArgumentException;
use madong\helper\Arr;
use Webman\Push\Api;

final class NotificationService
{
    private const DEFAULT_EXPIRE_DAYS = 30;

    protected ?Api $pushApi = null;
    protected Message $messageModel;

    public function __construct()
    {
        $config             = $this->getConfig();
        $this->pushApi      = new Api(
            $config['api'],
            $config['app_key'],
            $config['app_secret']
        );
        $this->messageModel = new Message();
    }

    public function sendAndRecord(
        PushClientType   $clientType,
        string           $businessModule,
        string|int|array $userIds,
        string           $event = 'message',
        array            $data = [],
        array            $messageData = [],
        ?string          $socketId = null
    ): array
    {
        $userIds = Arr::normalize($userIds);
        $result  = [
            'push_count' => 0,
            'messages'   => [],
        ];

        try {
            $messages = $this->createMessages(
                $userIds,
                array_merge($messageData, [
                    'type'       => $event,
                    'channel'    => $this->buildChannel($clientType, $businessModule, null),
                    'expired_at' => $this->calculateExpireTimestamp($messageData['expired_at'] ?? null),
                ]),
            );

            $pushData = array_merge($data, [
                'messages' => $this->formatMessagesForPush($messages),
            ]);

            $channels = array_map(
                fn($userId) => $this->buildChannel($clientType, $businessModule, $userId),
                $userIds
            );

            $this->pushApi->trigger($channels, $event, $pushData, $socketId);

            $result['push_count'] = count($channels);
            $result['messages']   = $messages;

            Logger::debug("推送成功", [
                'channels'      => $channels,
                'message_count' => count($messages),
            ]);

        } catch (\Throwable $e) {
            Logger::error("推送失败: " . $e->getMessage(), [
                'exception' => $e,
                'user_ids'  => $userIds,
            ]);
        }

        return $result;
    }

    public function batchSend(
        PushClientType $clientType,
        array          $messages
    ): array
    {
        $results = [];

        foreach ($messages as $msg) {
            $results[] = $this->sendAndRecord(
                $clientType,
                $msg['module'],
                $msg['receiver_id'],
                $msg['event'] ?? 'message',
                $msg['data'] ?? [],
                [
                    'title'        => $msg['title'] ?? '',
                    'content'      => $msg['content'] ?? '',
                    'type'         => $msg['message_type'] ?? $msg['event'],
                    'priority'     => $msg['priority'] ?? 0,
                    'related_id'   => $msg['related_id'] ?? null,
                    'expired_at'   => $msg['expired_at'] ?? null,
                    'message_uuid' => $msg['message_uuid'] ?? null,
                ]
            );
        }
        return $results;
    }

    public function pushOnly(
        PushClientType   $clientType,
        string           $businessModule,
        string|int|array $userIds,
        string           $event = 'message',
        array            $data = [],
        array            $messages = [],
        ?string          $socketId = null
    ): int
    {
        $userIds  = Arr::normalize($userIds);
        $channels = array_map(
            fn($userId) => $this->buildChannel($clientType, $businessModule, $userId),
            $userIds
        );

        $pushData = array_merge($data, [
            'messages' => $this->formatMessagesForPush($messages),
        ]);

        return $this->pushApi->trigger($channels, $event, $pushData, $socketId);
    }

    public function recordOnly(
        array $userIds,
        array $messageData
    ): array
    {
        return $this->createMessages(Arr::normalize($userIds), $messageData);
    }

    private function createMessages(array $receiverIds, array $data): array
    {
        $defaults = [
            'status'     => 'unread',
            'priority'   => 3,
            'expired_at' => $data['expired_at'] ?? $this->calculateExpireTimestamp(self::DEFAULT_EXPIRE_DAYS),
        ];

        $messages = [];
        foreach ($receiverIds as $receiverId) {
                 $message    = $this->messageModel->create(array_merge(
                $defaults,
                $data,
                ['message_uuid' => $this->generateMessageUuid($data['message_uuid'] ?? null)],
                ['receiver_id' => $receiverId]
            ));
            $messages[] = $message->toArray();
        }
        return $messages;
    }

    private function generateMessageUuid(?string $uuid = null): string
    {
        if (empty($uuid)) {
            try {
                return UUIDGenerator::generate();
            } catch (\Exception $e) {
                throw new \Exception("Failed to generate UUID: " . $e->getMessage());
            }
        }
        return $uuid;
    }

    private function calculateExpireTimestamp(int|null $expireDays): int
    {
        if ($expireDays === null) {
            $expireDays = self::DEFAULT_EXPIRE_DAYS;
        }
        return time() + ($expireDays * 86400);
    }

    private function formatMessagesForPush(array $messages): array
    {
        return array_map(function ($message) {
            return $message;
        }, $messages);
    }

    public function buildChannel(
        PushClientType $clientType,
        string         $businessModule,
        ?string        $userId = null
    ): string
    {
        if (!preg_match('/^[a-z0-9_]+$/', $businessModule)) {
            throw new InvalidArgumentException('业务模块只能包含小写字母、数字和下划线');
        }

        return implode('-', [
            $clientType->value,
            $businessModule,
            $userId ?: '*',
        ]);
    }

    private function getConfig(): array
    {
        $config = config('core.communication.notify.webman-push');
        if (empty($config)) {
            throw new \RuntimeException('消息推送配置未定义');
        }
        return $config;
    }
}