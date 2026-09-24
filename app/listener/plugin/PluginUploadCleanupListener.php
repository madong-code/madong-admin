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

namespace app\listener\plugin;

use app\service\core\upload\CloudResourceCleanerService;
use core\foundation\base\BaseListener;
use support\Log;

/**
 * 插件卸载后：按来源回收上传残留资源
 *
 * 回收范围以附件记录 sys_upload.source = plugin:{插件code} 精确匹配为主，
 * 并对 source 为空/为 default 的历史存量记录按 path/base_path 前缀兜底（仅限插件命名空间）。
 * 是否回收由插件自身 config/info.php 的 uninstall.remove_upload 开关决定，默认 true（回收），
 * 仅当显式配置为 false 时才跳过。
 *
 * 注意：回收失败只记录日志，绝不影响（也不回滚）卸载流程本身。
 */
class PluginUploadCleanupListener extends BaseListener
{
    protected function process($event): void
    {
        $code = (string)($event->code ?? '');
        if ($code === '') {
            return;
        }

        try {
            if (!$this->shouldRemoveUpload($code)) {
                Log::info('插件卸载：未开启上传资源回收，跳过', ['plugin' => $code]);
                return;
            }

            $report = (new CloudResourceCleanerService())->clean($code, ['yes' => true]);

            Log::info('插件卸载：上传资源已回收', [
                'plugin'   => $code,
                'prefix'   => $report['prefix'],
                'cloud'    => $report['cloud'],
                'local'    => $report['local'],
                'database' => $report['database'],
            ]);
        } catch (\Throwable $e) {
            // 兜底：任何异常都不能阻断卸载流程
            Log::error('插件卸载：上传资源回收失败', [
                'plugin' => $code,
                'error'  => $e->getMessage(),
                'file'   => $e->getFile(),
                'line'   => $e->getLine(),
            ]);
        }
    }

    /**
     * 读取插件 config/info.php 的 uninstall.remove_upload 开关
     *
     * 默认回收（true）：配置缺失或非法时同样回收，避免卸载后残留孤儿资源与死记录；
     * 仅当插件显式声明 remove_upload = false 时才跳过。
     */
    private function shouldRemoveUpload(string $code): bool
    {
        $configFile = base_path('plugin') . DIRECTORY_SEPARATOR . $code
            . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'info.php';

        if (!is_file($configFile)) {
            return true;
        }

        $config = include $configFile;

        if (!is_array($config)) {
            return true;
        }

        return ($config['uninstall']['remove_upload'] ?? true) !== false;
    }
}