<?php

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

/**
 * 插件安装基类
 * 使用 Trait 实现职责分离：
 * - ConfigTrait: 配置管理
 * - MigrationTrait: 迁移操作
 * - SeedTrait: 种子操作
 * - MenuTrait: 菜单操作
 * - TemplateTrait: 模板资源操作
 * - DependencyTrait: 依赖安装
 * 使用方式：
 * 1. 在插件目录创建 Install.php
 * 2. 继承此类即可，迁移和种子会自动执行
 * 3. 可重写 install/uninstall/update 方法添加自定义逻辑
 * 配置覆盖：
 * - 默认配置: core/plugin/config/default.php
 * - 应用配置: core/plugin/config/app.php
 * - 插件配置: plugin/{name}/config/info.php (可覆盖上述配置)
 */

namespace core\plugin;

use core\plugin\traits\ConfigTrait;
use core\plugin\traits\MigrationTrait;
use core\plugin\traits\SeedTrait;
use core\plugin\traits\MenuTrait;
use core\plugin\traits\TemplateTrait;
use core\plugin\traits\DependencyTrait;

class PluginInstall
{
    use ConfigTrait;
    use MigrationTrait;
    use SeedTrait;
    use MenuTrait;
    use TemplateTrait;
    use DependencyTrait;

    /** @var string 插件名称 */
    protected string $pluginName = '';

    /** @var string 插件路径 */
    protected string $pluginPath = '';

    /** @var string|null 连接名（默认使用框架配置） */
    protected ?string $connection = null;

    /** @var array 插件配置 */
    protected array $appConfig = [];

    /** @var callable|null 进度回调函数（用于在线模式通过 SSE 返回进度） */
    protected $progressCallback = null;

    /** @var bool 是否为在线模式（非 CLI） */
    protected bool $isOnlineMode = false;

    /**
     * @param callable|null $progressCallback 进度回调
     * @param bool          $isOnlineMode     是否为在线模式
     */
    public function __construct(?callable $progressCallback = null, bool $isOnlineMode = false)
    {
        $this->progressCallback = $progressCallback;
        $this->isOnlineMode     = $isOnlineMode;
        $this->init();
    }

    /**
     * 设置进度回调
     */
    public function setProgressCallback(?callable $callback): self
    {
        $this->progressCallback = $callback;
        return $this;
    }

    /**
     * 设置是否为在线模式
     */
    public function setOnlineMode(bool $isOnlineMode): self
    {
        $this->isOnlineMode = $isOnlineMode;
        return $this;
    }

    /**
     * 输出日志（CLI 模式 echo，在线模式通过回调）
     */
    protected function log(string $message, ?int $progress = null): void
    {
        if ($this->isOnlineMode && $this->progressCallback) {
            // 在线模式：通过回调返回进度
            ($this->progressCallback)($message, $progress);
        } else {
            // CLI 模式：直接输出
            echo $message . "\n";
        }
    }

    /**
     * 输出进度
     */
    protected function progress(string $message, int $progress): void
    {
        $this->log($message, $progress);
    }

    /**
     * 输出消息（用于 Trait 中的 echo 替换）
     * CLI 模式直接输出，在线模式输出到缓冲区
     */
    protected function output(string $message): void
    {
        echo $message . "\n";
    }

    /**
     * 初始化
     */
    protected function init(): void
    {
        $this->pluginPath = $this->getPluginPath();
        $this->pluginName = basename($this->pluginPath);
        $this->appConfig  = $this->getAppConfig();
    }

    /**
     * 插件路径 - 子类可重写
     */
    protected function getPluginPath(): string
    {
        $class = static::class;
        $parts = explode('\\', $class);
        if (count($parts) >= 2 && $parts[0] === 'plugin') {
            return base_path('plugin/' . $parts[1]);
        }
        return base_path('plugin');
    }

    /**
     * 插件名称
     */
    protected function getPluginName(): string
    {
        return $this->pluginName ?: basename($this->getPluginPath());
    }

    /**
     * 设置数据库连接
     */
    public function setConnection(?string $connection): self
    {
        $this->connection = $connection;
        return $this;
    }

    /**
     * 强制重新运行迁移
     */
    public function forceMigrate(): void
    {
        $this->output("⚠️ Force migration - clearing migration logs...");
        $this->clearLogs();
        $this->runMigrations();
    }

    /**
     * 安装
     */
    public function install($version): void
    {
        $this->log("====== Plugin Install: {$this->getPluginName()} ======", 0);
        $this->log("Version: {$version}");
        $this->log("Path: {$this->pluginPath}");

        // 安装前回调
        $this->beforeInstall($version);

        // 安装 Composer 依赖（后端）
        $this->installComposerDeps();

        // 运行迁移（后端）
        $this->runMigrations();

        // 运行种子（后端）
        $this->runSeeds();

        // 安装前端依赖
        $this->installNpmDeps();

        // 复制前端模板到各端
        $this->copyTemplates();

        // 执行各端安装命令
        $this->runInstallCommands();

        // 安装后回调 - 可重写用于导入菜单等操作
        $this->afterInstall($version);

        // 保存插件安装状态
        $this->savePluginConfig($version);

        $this->log("====== Install Completed ======", 100);
    }

    /**
     * 安装前回调 - 可重写
     */
    protected function beforeInstall(string $version): void
    {
        $this->log("  🔄 Running beforeInstall...");
    }

    /**
     * 安装后回调 - 可重写
     */
    protected function afterInstall(string $version): void
    {
        $this->log("  🔄 Running afterInstall...");

        // 加载插件菜单
        $this->loadMenus();
    }

    /**
     * 卸载前回调 - 可重写
     */
    protected function beforeUninstall(string $version): void
    {
        $this->log("  🔄 Running beforeUninstall...");
    }

    /**
     * 卸载后回调 - 可重写
     */
    protected function afterUninstall(string $version): void
    {
        $this->log("  🔄 Running afterUninstall...");

        // 删除插件菜单
        $this->clearMenus();
    }

    /**
     * 卸载
     */
    public function uninstall($version): void
    {
        $this->log("====== Plugin Uninstall: {$this->getPluginName()} ======", 0);

        // 卸载前回调
        $this->beforeUninstall($version);

        // 获取卸载配置
        $pluginInfo      = $this->getPluginInfo();
        $uninstallConfig = $pluginInfo['uninstall'] ?? [];
        $dropTables      = $uninstallConfig['drop_tables'] ?? false;

        $this->log("📋 Uninstall config: drop_tables=" . ($dropTables ? 'true' : 'false'));

        // 回滚迁移（可选删除表）
        $this->rollbackMigrations($dropTables);

        // 清理日志
        $this->clearLogs();

        // 卸载后回调
        $this->afterUninstall($version);

        // 删除前端模板
        $this->deleteTemplates();

        // 检查配置决定是否移除合并的依赖
        $globalUninstallConfig = $this->getConfig('uninstall', []);
        $removeDependencies    = $uninstallConfig['remove_dependencies'] ?? $globalUninstallConfig['remove_dependencies'] ?? false;

        $this->log("📋 Uninstall config: remove_dependencies=" . ($removeDependencies ? 'true' : 'false'));

        if ($removeDependencies) {
            $this->removeMergedDependencies();
        } else {
            $this->log("📦 Dependencies preserved in target projects. Remove manually if needed.");
        }

        // 删除插件配置文件
        $this->removePluginConfig();

        $this->log("====== Uninstall Completed ======", 100);
    }

    /**
     * 删除插件
     */
    public function delete($version): void
    {
        $this->log("====== Plugin Delete: {$this->getPluginName()} ======", 10);

        // 获取插件配置
        $pluginInfo      = $this->getPluginInfo();
        $uninstallConfig = $pluginInfo['uninstall'] ?? [];
        $undeletable     = $uninstallConfig['undeletable'] ?? false;

        $this->log("📋 Plugin config: undeletable=" . ($undeletable ? 'true' : 'false'), 20);

        // 检查是否允许删除
        if ($undeletable) {
            throw new \Exception('系统内置插件不允许删除');
        }

        // 删除前回调
        $this->beforeDelete($version);

        // 获取插件目录
        $pluginDir = $this->pluginPath;

        if (!is_dir($pluginDir)) {
            throw new \Exception('插件目录不存在：' . $pluginDir);
        }

        $this->log("📁 Deleting plugin directory: {$pluginDir}", 40);

        // 使用递归删除整个插件目录
        $this->removeDirectory($pluginDir);

        $this->log("✅ Plugin deleted successfully", 100);

        // 删除后回调
        $this->afterDelete($version);
    }

    /**
     * 删除前回调 - 可重写
     */
    protected function beforeDelete(string $version): void
    {
        $this->log("  🔄 Running beforeDelete...");
    }

    /**
     * 删除后回调 - 可重写
     */
    protected function afterDelete(string $version): void
    {
        $this->log("  🔄 Running afterDelete...");
    }

    /**
     * 递归删除目录
     */
    protected function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = scandir($directory);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $file;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }

    /**
     * 清除菜单 - 供子类在 afterUninstall 中调用
     */
    protected function clearMenus(): void
    {
        $this->clearPluginMenus($this->pluginName);
    }

    /**
     * 删除模板资源 - 供子类在 afterUninstall 中调用
     */
    protected function deletePluginTemplates(): void
    {
        $this->deleteTemplates();
    }

    /**
     * 更新
     */
    public function update($version): void
    {
        $this->output("====== Plugin Update: {$this->getPluginName()} ======");

        // 先卸载
        $this->uninstall($version);

        // 再安装
        $this->install($version);

        $this->output("====== Update Completed ======");
    }

    // =========================================================
    // 端安装命令执行
    // =========================================================

    /**
     * 执行各端安装命令
     */
    protected function runInstallCommands(): void
    {
        $this->output("📦 Running install commands...");

        $projectRoot = $this->getProjectRoot();

        // 后端安装命令
        $this->runBackendCommands($projectRoot);

        // 前端安装命令
        $this->runFrontendCommands($projectRoot);
    }

    /**
     * 执行后端安装命令
     */
    protected function runBackendCommands(string $projectRoot): void
    {
        // 可在子类中重写实现自定义命令
    }

    /**
     * 执行前端安装命令
     */
    protected function runFrontendCommands(string $projectRoot): void
    {
        // 可在子类中重写实现自定义命令
    }
}
