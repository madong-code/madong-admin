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
namespace app\enum\plugin;

use core\business\plugin\PluginPath;

enum FrontendType: string
{
    case WEB = 'web';
    case ADMIN = 'admin';
    case MOBILE = 'mobile';
    case H5 = 'h5';
    case DESKTOP = 'desktop';

    /**
     * 获取相对路径模板（含一个插件名占位符）
     * 注意：路径是相对于项目根目录（backend 的父目录）
     */
    public function pathTemplate(): string
    {
        return match($this) {
            self::WEB => 'template/web/src/plugin/%s',
            self::ADMIN => 'template/admin/src/plugin/%s',
            self::MOBILE => 'template/mobile/src/apps/%s',
            self::H5 => 'template/h5/public/apps/%s',
            self::DESKTOP => 'template/desktop/build/apps/%s',
        };
    }

    /**
     * 检查是否为已知类型
     */
    public static function isKnownType(string $type): bool
    {
        return in_array($type, array_column(self::cases(), 'value'), true);
    }
}