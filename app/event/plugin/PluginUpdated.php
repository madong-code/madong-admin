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
namespace app\event\plugin;

use core\foundation\base\BaseEvent;

/**
 * 插件更新后事件
 */
class PluginUpdated extends BaseEvent
{
    public string $code;
    public string $version;
    public array $extra;

    public function __construct(string $code, string $version, array $extra = [])
    {
        $this->code = $code;
        $this->version = $version;
        $this->extra = $extra;
    }

    public function getEventName(): string
    {
        return 'plugin.updated';
    }
}
