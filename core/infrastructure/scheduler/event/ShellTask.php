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
use core\infrastructure\scheduler\event\EventBootstrap;

class ShellTask implements EventBootstrap
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
            $target = $crontab['target'] ?? '';
            $log = shell_exec($target);
        } catch (\Throwable $e) {
            $code = OperationResult::FAILURE->value;
            $log  = $e->getMessage();
        }
        return ['code' => $code, 'log' => $log];
    }

}
