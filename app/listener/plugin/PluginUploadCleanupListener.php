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
 * 插件卸载后：按目录回收上传残留资源
 *
 * 插件资源目录约定为 {dirname}/{插件code}/（dirname 取自当前存储驱动配置，默认 upload）。
 * 是否回收由插件自身 config/info.php 的 uninstall.remove_upload 开关决定，默认 false（不回收）。
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
     */
    private function shouldRemoveUpload(string $code): bool
    {
        $configFile = base_path('plugin') . DIRECTORY_SEPARATOR . $code
            . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'info.php';

        if (!is_file($configFile)) {
            return false;
        }

        $config = include $configFile;

        return is_array($config) && !empty($config['uninstall']['remove_upload']);
    }
}