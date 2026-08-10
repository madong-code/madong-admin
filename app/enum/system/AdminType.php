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
 * Official Website: https://madong.tech
 */

namespace app\enum\system;

/**
 * 管理员类型
 *
 * 用于区分平台运营端和管理端的管理员账号：
 * - ROOT: 全局超管，仅存在于主库
 * - PLATFORM: 平台运营端管理员，登录 /platformapi
 *
 * @author Mr.April
 * @since  1.0
 */
enum AdminType: string
{
    case ROOT = 'root';
    case PLATFORM = 'platform';

    /**
     * 获取人类可读的标签
     */
    public function label(): string
    {
        return match ($this) {
            self::ROOT => '全局超管',
            self::PLATFORM => '平台管理员',
        };
    }

    /**
     * 获取颜色标识
     */
    public function color(): string
    {
        return match ($this) {
            self::ROOT => 'red',
            self::PLATFORM => 'purple',
        };
    }

    /**
     * 检查是否为平台级管理员
     */
    public static function isPlatformLevel(string $code): bool
    {
        return in_array($code, [self::ROOT->value, self::PLATFORM->value]);
    }
}
