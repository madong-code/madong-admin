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
namespace core\business\plugin\traits;

/**
 * 依赖与模板操作事务 Trait
 *
 * 为插件安装/卸载提供「快照 + 备份 + 回滚」能力：
 * - 快照：composer.json / package.json / config/installed.php 原文
 * - 备份：卸载时模板目录移动到备份目录（而不是直接删除），失败可移回
 * - 回滚：还原声明文件、移回模板、删除安装过程中新建的模板目录
 *
 * 事务范围仅限「声明文件」与「模板目录」两类可回滚资源，
 * 数据库迁移回滚沿用 MigrationTrait::rollbackMigrations()。
 */
trait DependencyTransactionTrait
{
    /**
     * 事务是否开启
     */
    private bool $transactionActive = false;

    /**
     * 文件快照：文件路径 => 原文（null 表示原本不存在）
     */
    private array $fileSnapshots = [];

    /**
     * 已备份的模板目录：备份路径 => 原始路径
     */
    private array $backedUpTemplates = [];

    /**
     * 安装过程中创建的模板目录
     */
    private array $createdTemplates = [];

    /**
     * 备份根目录
     */
    private ?string $backupDir = null;

    /**
     * 事务是否开启
     */
    protected function hasTransaction(): bool
    {
        return $this->transactionActive;
    }

    /**
     * 开启事务
     *
     * @param string $action 操作标识（install/uninstall）
     */
    protected function beginTransaction(string $action): void
    {
        if ($this->transactionActive) {
            return;
        }

        $this->transactionActive = true;
        $this->fileSnapshots     = [];
        $this->backedUpTemplates = [];
        $this->createdTemplates  = [];

        $name       = method_exists($this, 'getPluginName') ? $this->getPluginName() : ($this->pluginName ?? 'plugin');
        $this->backupDir = runtime_path('install/plugin/backup/' . $name . '_' . $action . '_' . date('Ymd-His'));
        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0755, true);
        }

        // 快照依赖声明文件
        $this->snapshotFile(base_path('composer.json'));
        foreach (['admin', 'web'] as $endpoint) {
            $path = $this->getFrontendProjectPath($endpoint);
            $this->snapshotFile($path . DIRECTORY_SEPARATOR . 'package.json');
        }

        // 快照插件安装状态文件
        $installedFile = method_exists($this, 'getPluginConfigFile') ? $this->getPluginConfigFile() : '';
        if ($installedFile !== '') {
            $this->snapshotFile($installedFile);
        }
    }

    /**
     * 提交事务（清理备份）
     */
    protected function commitTransaction(): void
    {
        if (!$this->transactionActive) {
            return;
        }

        $this->transactionActive = false;

        if ($this->backupDir && is_dir($this->backupDir)) {
            $this->removeBackupDirectory($this->backupDir);
        }

        $this->backupDir         = null;
        $this->fileSnapshots     = [];
        $this->backedUpTemplates = [];
        $this->createdTemplates  = [];
    }

    /**
     * 回滚事务（还原声明文件 + 移回模板 + 清理新建模板）
     */
    protected function rollbackTransaction(): void
    {
        if (!$this->transactionActive) {
            return;
        }

        $this->transactionActive = false;

        try {
            // 1. 还原声明文件
            foreach ($this->fileSnapshots as $file => $content) {
                if ($content === null) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                    continue;
                }
                @file_put_contents($file, $content, LOCK_EX);
            }

            // 2. 移回模板目录
            foreach ($this->backedUpTemplates as $backupPath => $originPath) {
                if (!is_dir($backupPath)) {
                    continue;
                }
                if (is_dir($originPath)) {
                    continue;
                }
                if (!is_dir(dirname($originPath))) {
                    @mkdir(dirname($originPath), 0755, true);
                }
                @rename($backupPath, $originPath);
            }

            // 3. 删除安装过程中创建的模板目录
            foreach ($this->createdTemplates as $dir) {
                if (is_dir($dir)) {
                    $this->removeBackupDirectory($dir);
                }
            }

            $this->output("  ↩️ 已回滚本次操作的依赖声明与模板改动");
        } catch (\Throwable $e) {
            $this->output("  ⚠️ 回滚过程异常: " . $e->getMessage());
        }

        $this->backupDir         = null;
        $this->fileSnapshots     = [];
        $this->backedUpTemplates = [];
        $this->createdTemplates  = [];
    }

    /**
     * 快照文件（仅在事务开启时生效）
     */
    protected function snapshotFile(string $file): void
    {
        if (!$this->transactionActive) {
            return;
        }

        if (array_key_exists($file, $this->fileSnapshots)) {
            return;
        }

        $this->fileSnapshots[$file] = is_file($file) ? (string)file_get_contents($file) : null;
    }

    /**
     * 备份模板目录（移动到备份目录，而不是直接删除）
     *
     * @return bool 备份成功返回 true，失败（如跨设备 rename 失败）返回 false
     */
    protected function backupTemplateDir(string $endpoint, string $dir): bool
    {
        if (!$this->transactionActive || !$this->backupDir || !is_dir($dir)) {
            return false;
        }

        $target = $this->backupDir . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR . $endpoint;
        if (!is_dir(dirname($target))) {
            @mkdir(dirname($target), 0755, true);
        }
        if (is_dir($target)) {
            $this->removeBackupDirectory($target);
        }

        if (!@rename($dir, $target)) {
            return false;
        }

        $this->backedUpTemplates[$target] = $dir;

        return true;
    }

    /**
     * 记录安装过程中创建的模板目录（回滚时删除）
     */
    protected function trackCreatedTemplate(string $dir): void
    {
        if (!$this->transactionActive) {
            return;
        }

        if (is_dir($dir) && !in_array($dir, $this->createdTemplates, true)) {
            $this->createdTemplates[] = $dir;
        }
    }

    /**
     * 递归删除目录（事务内部使用，不抛异常）
     */
    private function removeBackupDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = @scandir($directory);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path) && !is_link($path)) {
                $this->removeBackupDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
