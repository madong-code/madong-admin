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

use core\infrastructure\logger\Logger;
use Webman\RedisQueue\Consumer;

/**
 * 队列消费者基类
 *
 * 自动处理：日志记录、失败重试、死信标记。
 * 子类只需实现 handle() 执行业务逻辑，定义 $queue 队列名。
 *
 * @property string $queue 队列名（子类必须定义）
 */
abstract class BaseQueueConsumer implements Consumer
{
    /**
     * Redis 连接名，默认 default
     */
    public string $connection = 'default';

    /**
     * 最大重试次数，子类可覆盖
     */
    protected int $maxRetry = 3;

    /**
     * 执行业务逻辑
     *
     * @param array $data
     */
    abstract protected function handle(array $data): void;

    /**
     * 日志标签，默认取短类名，子类可覆盖
     */
    protected function getLogTag(): string
    {
        return (new \ReflectionClass($this))->getShortName();
    }

    /**
     * {@inheritdoc}
     */
    final public function consume($data): bool
    {
        $tag = $this->getLogTag();

        try {
            Logger::info("[{$tag}] 开始消费", ['data' => $data]);
            $this->handle($data);
            Logger::info("[{$tag}] 消费完成");
            return true;
        } catch (\Throwable $e) {
            Logger::error("[{$tag}] 消费失败", [
                'error' => $e->getMessage(),
                'data'  => $data,
            ]);
            throw $e;
        }
    }
}