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
namespace app\adminapi\event\content;

use core\communication\notify\enum\PushClientType;
use core\foundation\base\BaseEvent;
use Webman\Event\Event;

/**
 * 消息推送事件
 * 统一推送入口，事件监听器负责调用 MessageNotifyService::send() (含订阅过滤)
 *
 * @see \app\adminapi\listener\content\MessagePushListener
 * @see \app\service\admin\content\message\NotifyService::send()
 */
class MessagePushEvent extends BaseEvent
{
    public PushClientType $clientType;
    public string $businessModule;
    public string|int|array $userIds;
    public string $event;
    public array $data;
    public array $messageData;
    public ?string $socketId;
    public string $scene;
    /** @var bool 是否强制推送（跳过订阅过滤），用于系统级重要通知 */
    public bool $isForce = false;

    public function __construct(
        PushClientType $clientType,
        string $businessModule,
        string|int|array $userIds,
        string $event = 'message',
        array $data = [],
        array $messageData = [],
        ?string $socketId = null,
        string $scene = '',
        bool $isForce = false
    ) {
        $this->clientType = $clientType;
        $this->businessModule = $businessModule;
        $this->userIds = $userIds;
        $this->event = $event;
        $this->data = $data;
        $this->messageData = $messageData;
        $this->socketId = $socketId;
        $this->scene = $scene;
        $this->isForce = $isForce;
    }

    public function getEventName(): string
    {
        return 'adminapi.message.push';
    }

    /**
     * 触发推送事件
     */
    public function dispatch()
    {
        $name = $this->getEventName();
        Event::emit($name, $this);
    }
}
