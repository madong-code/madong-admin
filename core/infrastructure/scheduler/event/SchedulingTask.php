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
namespace core\infrastructure\scheduler\event;

use app\enum\system\OperationResult;
use core\business\plugin\support\PluginTaskGuard;
use core\infrastructure\scheduler\event\EventBootstrap;

class SchedulingTask implements EventBootstrap
{
    /**
     * @param $crontab
     *
     * @return array
     */
    public static function parse($crontab): array
    {
        $code = OperationResult::SUCCESS->value;
        try {
            $className = (string) $crontab['target'];
            $bizId     = $crontab['biz_id'];

            // target 命名空间形如 plugin\{code}\... 时，先验证插件已安装。
            // 卸载时 PluginUninstallService 会清理 sys_crontab 条目，
            // 这里作为兜底，避免手动删表/迁移残留导致每轮扫描都抛出异常。
            $pluginCode = self::extractPluginCode($className);
            if ($pluginCode !== null && !PluginTaskGuard::isInstalled($pluginCode)) {
                return ['log' => "plugin [{$pluginCode}] not installed, skip target: {$className}", 'code' => $code];
            }

            if (!class_exists($className)) {
                return ['log' => "target class [{$className}] not found, skip target: {$className}", 'code' => $code];
            }

            $instance         = new $className();
            $reflectionMethod = new \ReflectionMethod($className, 'run');
            $log              = $reflectionMethod->invokeArgs($instance, [$bizId]);
        } catch (\Throwable $throwable) {
            $code = OperationResult::FAILURE->value;
            $log  = $throwable->getMessage();
        }
        return ['log' => $log, 'code' => $code];
    }

    /**
     * 从 target 类名反推插件编码（plugin\report\app\... → report），非插件命名空间返回 null。
     */
    private static function extractPluginCode(string $className): ?string
    {
        if (!str_starts_with($className, 'plugin\\')) {
            return null;
        }
        return explode('\\', $className)[1] ?? null;
    }

}
