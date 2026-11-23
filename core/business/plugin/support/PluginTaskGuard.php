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

namespace core\business\plugin\support;

use support\Db;

/**
 * 插件定时任务守卫
 *
 * 用途：sys_crontab / process / command 三类任务入口的统一前置守卫。
 * 未安装插件（config/installed.php 缺失）或依赖表不存在时，直接跳过执行。
 *
 * 为什么要双层守卫：
 *   1. installed.php 守卫 — 插件卸载时会被 PluginUninstallService 同步清理，
 *      但极端场景（手动删表、迁移残留）下依然可能存在 sys_crontab 遗留条目。
 *   2. hasTable 守卫 — 比 Db::table('xxx')->count() try/catch 更高效：
 *      Schema::hasTable 走 information_schema，命中缓存时开销很小。
 *
 * 用法（插件任务入口 run() 顶部，命中守卫时返回安全的空结果，不继续查询插件表）：
 *   use core\business\plugin\support\PluginTaskGuard;
 *
 *   public function run($bizId): string
 *   {
 *       if (PluginTaskGuard::abortIfMissing('report', ['report_export_file', 'report_task_log'], $reason)) {
 *           return "skip: {$reason}";
 *       }
 *       // 之后才访问插件表 ...
 *   }
 */
final class PluginTaskGuard
{
    /**
     * 插件是否已安装（installed.php 存在）
     */
    public static function isInstalled(string $pluginCode): bool
    {
        $path = base_path() . "/plugin/{$pluginCode}/config/installed.php";
        return is_file($path);
    }

    /**
     * 列出的表是否都存在（空数组返回 true）
     *
     * @param string[] $tables
     */
    public static function hasTables(array $tables): bool
    {
        if ($tables === []) {
            return true;
        }
        try {
            $schema = Db::connection()->getSchemaBuilder();
            foreach ($tables as $table) {
                if (!$schema->hasTable($table)) {
                    return false;
                }
            }
            return true;
        } catch (\Throwable) {
            // DB 连接异常（MySQL 未启动、权限等）一律视为“不可执行”，交由外层记录日志
            return false;
        }
    }

    /**
     * 未安装或依赖表不全时返回 true 并写入跳过原因；否则返回 false。
     *
     * @param string      $pluginCode 插件编码
     * @param string[]    $tables     必须存在的依赖表
     * @param string|null $reason     跳过原因（abort 时写入）
     * @return bool true 表示已 abort，false 表示可以继续执行
     */
    public static function abortIfMissing(string $pluginCode, array $tables = [], ?string &$reason = null): bool
    {
        if (!self::isInstalled($pluginCode)) {
            $reason = "plugin {$pluginCode} not installed (installed.php missing), skip scheduled task";
            return true;
        }
        if ($tables !== [] && !self::hasTables($tables)) {
            $reason = "plugin {$pluginCode} required tables missing, skip scheduled task";
            return true;
        }
        return false;
    }
}
