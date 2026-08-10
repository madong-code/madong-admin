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

namespace core\foundation\base;
use Webman\Event\Event;

/**
 * 事件抽象基类
 *
 * 所有模块的事件统一继承此类。
 */
abstract class BaseEvent
{
    /**
     * 获取事件名称（子类必须实现）
     */
    abstract public function getEventName(): string;

    /**
     * 触发事件
     * 子类可覆写以自定义返回值（如 MenuFormattingEvent 返回 $this->result）
     */
    public function dispatch()
    {
        Event::emit($this->getEventName(), $this);
    }
}