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

use core\business\plugin\DependencyRegistry;
use core\business\plugin\DependencyRunner;

/**
 * 依赖安装 Trait
 *
 * 流水线：声明合并（含冲突策略）→ 归属登记 → 真实安装（可选）→ lock 同步 → 状态持久化
 *
 * - auto_install=false（默认）：只合并声明，输出待执行命令清单，依赖状态记为 pending
 * - auto_install=true：真正执行 composer/pnpm 命令，成功记 installed，失败记 failed
 * - 安装失败默认不阻断插件安装（on_failure=continue），状态持久化到插件 config/installed.php
 * - 卸载时按引用计数剥离：仍被其它插件使用的依赖保留
 */
trait DependencyTrait
{
    /**
     * 是否强制真实安装依赖（补装入口使用，覆盖 auto_install 配置）
     */
    protected bool $forceDependencyInstall = false;

    /**
     * 本次操作记录的依赖状态
     */
    private array $dependencyState = [];

    /**
     * 本次安装「真正新增」的依赖（区别于框架/主项目预置的共用依赖）
     * 仅这些依赖在卸载时才允许被剥离，避免误删共用依赖
     */
    private array $addedDependencies = [];

    /**
     * 设置是否强制真实安装依赖
     */
    public function setForceDependencyInstall(bool $force): self
    {
        $this->forceDependencyInstall = $force;
        return $this;
    }

    /**
     * 是否执行真实安装
     *
     * 仅「补装依赖」（force=true）才真正执行 composer/pnpm 命令；
     * 「插件安装」流程一律只合并依赖声明（pending），不做真实下载，
     * 以免阻塞 worker 导致 SSE 连接断开、后端失联。
     * 真实下载由独立的「补装依赖」手动操作完成。
     */
    protected function shouldAutoInstall(): bool
    {
        return $this->forceDependencyInstall;
    }

    /**
     * 合并 Composer 依赖（后端）
     */
    protected function installComposerDeps(): void
    {
        $this->addedDependencies = [];

        $pluginInfo = $this->getPluginInfo();
        $require    = $pluginInfo['require']['composer'] ?? [];

        if (empty($require)) {
            $this->output("📦 No Composer dependencies to merge");
            $this->recordDependencyStatus('composer', []);
            return;
        }

        $config = $this->getConfig('dependencies.backend', []);
        if (empty($config['enabled'])) {
            $this->output("📦 Composer dependency merge disabled");
            $this->recordDependencyStatus('composer', []);
            return;
        }

        $this->output("📦 Merging Composer dependencies...");

        $composerFile = base_path('composer.json');
        if (!is_file($composerFile)) {
            $this->output("  ⚠️ composer.json not found");
            $this->recordDependencyStatus('composer', $this->buildStatus($require, 'failed', 'composer.json 不存在'));
            return;
        }

        $composer = json_decode((string)file_get_contents($composerFile), true);
        if (!is_array($composer)) {
            $this->output("  ⚠️ Invalid composer.json format");
            $this->recordDependencyStatus('composer', $this->buildStatus($require, 'failed', 'composer.json 格式错误'));
            return;
        }

        // 事务快照，安装失败可还原
        if (method_exists($this, 'snapshotFile')) {
            $this->snapshotFile($composerFile);
        }

        $strategy = (string)$this->getConfig('dependencies.conflict.strategy', 'skip');
        $merged   = false;
        $existing = []; // 安装前已存在的包（无论版本是否一致，视为已满足）

        foreach ($require as $package => $version) {
            $package = (string)$package;
            $version = (string)$version;

            if (!isset($composer['require'][$package])) {
                $composer['require'][$package] = $version;
                $merged                        = true;
                $this->output("  ➕ Added to require: {$package}:{$version}");
                continue;
            }

            $current = (string)$composer['require'][$package];
            $existing[$package] = $current;

            if ($current === $version) {
                $this->output("  ℹ️ Already declared: {$package}:{$current}");
                continue;
            }

            // 版本冲突
            if ($strategy === 'fail') {
                throw new \RuntimeException("依赖冲突：{$package} 现有版本 {$current}，插件要求 {$version}");
            }

            if ($strategy === 'override') {
                $composer['require'][$package] = $version;
                $merged                        = true;
                $this->output("  ♻️ Overridden: {$package}: {$current} → {$version}");
                continue;
            }

            $this->output("  ⚠️ 版本冲突已跳过：{$package} 现有 {$current}，插件要求 {$version}（conflict.strategy=skip）");
        }

        if ($merged) {
            @file_put_contents(
                $composerFile,
                json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
                LOCK_EX
            );
        }

        // 登记归属（无论是否已声明，本插件都是使用者）
        $this->dependencyRegistry()->register($this->getPluginName(), 'composer', $require);

        $runner   = $this->dependencyRunner();
        $workDir  = base_path();
        $statuses = [];

        if (!$this->shouldAutoInstall()) {
            $runner->pending(
                $this->describeComposerInstall($require),
                $workDir,
                'Composer 依赖安装'
            );
            if ((bool)$this->getConfig('dependencies.lock.sync', true)) {
                $runner->pending('composer update --lock --no-interaction --no-scripts', $workDir, 'composer.lock 同步');
            }
            $statuses = $this->buildStatus($require, 'pending', '等待手动安装');
        } else {
            $bin = (string)$this->getConfig('dependencies.composer.bin', 'composer');
            if (!$runner->isAvailable($bin)) {
                $this->output("  ⚠️ {$bin} 不可用，依赖声明已合并，需手动安装");
                $statuses = $this->buildStatus($require, 'pending', "{$bin} 不可用");
            } else {
                $ok = $runner->installBackend($require, $workDir);
                if ($ok && (bool)$this->getConfig('dependencies.lock.sync', true)) {
                    $runner->syncComposerLock($workDir);
                }
                $statuses = $this->buildStatus(
                    $require,
                    $ok ? 'installed' : 'failed',
                    $ok ? '' : $runner->getLastError()
                );

                if (!$ok && $this->getConfig('dependencies.on_failure', 'continue') === 'abort') {
                    throw new \RuntimeException('后端依赖安装失败：' . $runner->getLastError());
                }
            }
        }

        // 已存在的包直接标记为已安装（无论是否为本次新增）
        foreach ($existing as $pkg => $ver) {
            $statuses[(string)$pkg] = [
                'status'     => 'installed',
                'version'    => (string)$ver,
                'error'      => '已存在，无需安装',
                'updated_at' => date('Y-m-d H:i:s'),
            ];
        }

        // 仅记录「本次真正新增」的包（已存在的预置依赖不算），供卸载时安全剥离
        $added = array_diff(array_keys($require), array_keys($existing));
        $this->addedDependencies['composer'] = array_values($added);

        $this->recordDependencyStatus('composer', $statuses);
        $runner->outputPendingCommands();
    }

    /**
     * 合并 NPM 依赖（前端）
     */
    protected function installNpmDeps(): void
    {
        $pluginInfo = $this->getPluginInfo();
        $npmRequire = $pluginInfo['npm_require'] ?? [];

        if (empty($npmRequire)) {
            $this->output("📦 No NPM dependencies to merge");
            $this->recordDependencyStatus('admin', []);
            $this->recordDependencyStatus('web', []);
            return;
        }

        $runner = $this->dependencyRunner();

        foreach (['admin', 'web'] as $endpoint) {
            $endpointConfig = $this->getConfig("dependencies.{$endpoint}", []);
            if (empty($endpointConfig['enabled'])) {
                $this->output("📦 {$endpoint}: NPM dependency merge disabled");
                $this->recordDependencyStatus($endpoint, []);
                continue;
            }

            $workDir  = $this->getFrontendProjectPath($endpoint);
            $packages = array_merge($npmRequire[$endpoint] ?? [], $npmRequire['common'] ?? []);

            if (empty($packages)) {
                $this->recordDependencyStatus($endpoint, []);
                continue;
            }

            // 记录合并前已存在的包（视为已满足）
            $existing = [];
            $origFile = $workDir . '/package.json';
            if (is_file($origFile)) {
                $orig = json_decode((string)file_get_contents($origFile), true);
                if (is_array($orig)) {
                    foreach (['dependencies', 'devDependencies'] as $sec) {
                        foreach (array_keys($orig[$sec] ?? []) as $pkg) {
                            $existing[(string)$pkg] = $orig[$sec][$pkg];
                        }
                    }
                }
            }

            $this->mergeNpmDeps($packages, $workDir, $endpoint, $endpointConfig);

            // 登记归属
            $this->dependencyRegistry()->register($this->getPluginName(), $endpoint, $packages);

            if (!is_dir($workDir)) {
                $this->recordDependencyStatus($endpoint, $this->buildStatus($packages, 'failed', "目录不存在: {$workDir}"));
                continue;
            }

            if (!$this->shouldAutoInstall()) {
                $manager = $runner->detectManager($workDir);
                $runner->pending("{$manager} install", $workDir, "{$endpoint} 前端依赖安装");
                $statuses = $this->buildStatus($packages, 'pending', '等待手动安装');
            } elseif (!$runner->isAvailable($runner->detectManager($workDir))) {
                $this->output("  ⚠️ {$endpoint}: 包管理器不可用，依赖声明已合并，需手动安装");
                $statuses = $this->buildStatus($packages, 'pending', '包管理器不可用');
            } else {
                $ok = $runner->installFrontend($workDir, $endpoint);
                $statuses = $this->buildStatus(
                    $packages,
                    $ok ? 'installed' : 'failed',
                    $ok ? '' : $runner->getLastError()
                );

                if (!$ok && $this->getConfig('dependencies.on_failure', 'continue') === 'abort') {
                    throw new \RuntimeException("{$endpoint} 前端依赖安装失败：" . $runner->getLastError());
                }
            }

            // 已存在的包直接标记为已安装
            foreach ($existing as $pkg => $ver) {
                if (isset($packages[$pkg])) {
                    $statuses[(string)$pkg] = [
                        'status'     => 'installed',
                        'version'    => (string)$ver,
                        'error'      => '已存在，无需安装',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ];
                }
            }

            // 仅记录「本次真正新增」的包（已存在的预置依赖不算），供卸载时安全剥离
            $added = array_diff(array_keys($packages), array_keys($existing));
            $this->addedDependencies[$endpoint] = array_values($added);

            $this->recordDependencyStatus($endpoint, $statuses);
        }

        $runner->outputPendingCommands();
    }

    /**
     * 合并 NPM 依赖到 package.json
     */
    protected function mergeNpmDeps(array $packages, string $workDir, string $endpoint, array $config): void
    {
        if (empty($packages) || !is_dir($workDir)) {
            return;
        }

        $packageFile = $workDir . '/package.json';
        if (!is_file($packageFile)) {
            $this->output("  ⚠️ {$endpoint}: package.json not found");
            return;
        }

        if (method_exists($this, 'snapshotFile')) {
            $this->snapshotFile($packageFile);
        }

        $package = json_decode((string)file_get_contents($packageFile), true);
        if (!is_array($package)) {
            $this->output("  ⚠️ {$endpoint}: Invalid package.json format");
            return;
        }

        $strategy = (string)$this->getConfig('dependencies.conflict.strategy', 'skip');
        $merged   = false;

        // 同一个包只写入一处：优先 dependencies（生产依赖），未开启时才写入 devDependencies
        $section = !empty($config['merge_prod']) ? 'dependencies' : 'devDependencies';
        if (empty($config['merge_prod']) && empty($config['merge_dev'])) {
            return;
        }

        foreach ($packages as $pkg => $version) {
            $pkg     = (string)$pkg;
            $version = (string)$version;

            if (!isset($package[$section][$pkg])) {
                $package[$section][$pkg] = $version;
                $merged                  = true;
                $this->output("  ➕ {$endpoint}: Added {$pkg}@{$version} ({$section})");
                continue;
            }

            $current = (string)$package[$section][$pkg];
            if ($current === $version) {
                continue;
            }

            if ($strategy === 'fail') {
                throw new \RuntimeException("依赖冲突：{$endpoint} {$pkg} 现有版本 {$current}，插件要求 {$version}");
            }

            if ($strategy === 'override') {
                $package[$section][$pkg] = $version;
                $merged                  = true;
                $this->output("  ♻️ {$endpoint}: Overridden {$pkg}: {$current} → {$version}");
                continue;
            }

            $this->output("  ⚠️ {$endpoint}: 版本冲突已跳过 {$pkg} 现有 {$current}，插件要求 {$version}");
        }

        if ($merged) {
            @file_put_contents(
                $packageFile,
                json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
                LOCK_EX
            );
        }
    }

    /**
     * 移除合并的依赖（仅剥离「本次真正新增」的依赖，保护框架/主项目预置的共用依赖）
     */
    protected function removeMergedDependencies(): void
    {
        $this->output("🗑️ Removing merged dependencies...");

        $registry   = $this->dependencyRegistry();
        $runner     = $this->dependencyRunner();
        $plugin     = $this->getPluginName();

        // 仅移除本次安装真正新增的依赖；框架/主项目预置的共用依赖（pre-existing）一律不碰
        $config = $this->getPluginConfig() ?? [];
        $added  = is_array($config['added_dependencies'] ?? null) ? $config['added_dependencies'] : [];

        // 兜底：按端点（composer/admin/web）独立回退到 info.php 声明。
        // 旧逻辑用 empty($added) 判断整张表，只要任一端点记录过依赖（如 composer），
        // 就会让其它端点（如 admin）即使未记录也跳过回退，导致卸载时漏删该端点声明。
        // 改为逐端点判断，确保 remove_dependencies=true 时各端点声明的依赖都能被安全剥离。
        $pluginInfo   = $this->getPluginInfo();
        $composerReq  = $pluginInfo['require']['composer'] ?? [];
        $npmRequire   = $pluginInfo['npm_require'] ?? [];
        $fallback = [
            'composer' => array_keys($composerReq),
            'admin'    => array_keys(array_merge($npmRequire['admin'] ?? [], $npmRequire['common'] ?? [])),
            'web'      => array_keys(array_merge($npmRequire['web'] ?? [], $npmRequire['common'] ?? [])),
        ];
        foreach (['composer', 'admin', 'web'] as $key) {
            if (empty($added[$key])) {
                $added[$key] = $fallback[$key] ?? [];
            }
        }

        $added = is_array($added) ? $added : [];

        // 移除 Composer 依赖
        $composerAdded = $added['composer'] ?? [];
        if (!empty($composerAdded)) {
            $orphans = [];
            foreach ($composerAdded as $package) {
                $package = (string)$package;
                if ($registry->isUsedByOthers('composer', $package, $plugin)) {
                    $this->output("  ⏸️ composer:{$package} 仍被其它插件使用，保留（" . implode(',', $registry->getOwners('composer', $package)) . "）");
                    continue;
                }
                $orphans[$package] = '*';
            }

            if (!empty($orphans)) {
                if ($this->shouldAutoInstall() && $runner->isAvailable((string)$this->getConfig('dependencies.composer.bin', 'composer'))) {
                    $runner->removeBackend($orphans, base_path());
                } else {
                    $this->removeComposerDeps($orphans);
                    if ((bool)$this->getConfig('dependencies.lock.sync', true)) {
                        $runner->pending('composer update --lock --no-interaction --no-scripts', base_path(), 'composer.lock 同步');
                    }
                }
            }
        }

        // 移除 NPM 依赖
        foreach (['admin', 'web'] as $endpoint) {
            $npmAdded = $added[$endpoint] ?? [];
            if (empty($npmAdded)) {
                continue;
            }

            $workDir = $this->getFrontendProjectPath($endpoint);
            $orphans = [];

            foreach ($npmAdded as $pkg) {
                $pkg = (string)$pkg;
                if ($registry->isUsedByOthers($endpoint, $pkg, $plugin)) {
                    $this->output("  ⏸️ {$endpoint}:{$pkg} 仍被其它插件使用，保留");
                    continue;
                }
                $orphans[$pkg] = '*';
            }

            if (empty($orphans) || !is_dir($workDir)) {
                continue;
            }

            $manager = $runner->detectManager($workDir);
            if ($this->shouldAutoInstall() && $runner->isAvailable($manager)) {
                $runner->removeFrontend($orphans, $workDir, $endpoint);
            } else {
                $this->removeNpmDeps($orphans, $workDir, $endpoint);
                $runner->pending("{$manager} install", $workDir, "{$endpoint} lock 文件更新");
            }
        }

        // 释放本插件的归属登记
        $registry->release($plugin);

        $this->output("  ✅ Dependencies removed. Run 'composer install' or 'npm install' to update.");
        $runner->outputPendingCommands();
    }

    /**
     * 移除 Composer 依赖声明
     */
    protected function removeComposerDeps(array $packages): void
    {
        $composerFile = base_path('composer.json');
        if (!is_file($composerFile)) {
            return;
        }

        if (method_exists($this, 'snapshotFile')) {
            $this->snapshotFile($composerFile);
        }

        $composer = json_decode((string)file_get_contents($composerFile), true);
        if (!is_array($composer)) {
            return;
        }

        $removed = false;
        foreach (array_keys($packages) as $package) {
            if (isset($composer['require'][$package])) {
                unset($composer['require'][$package]);
                $removed = true;
                $this->output("  ➖ Removed composer: {$package}");
            }
        }

        if ($removed) {
            @file_put_contents(
                $composerFile,
                json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
                LOCK_EX
            );
        }
    }

    /**
     * 移除 NPM 依赖声明
     */
    protected function removeNpmDeps(array $packages, string $workDir, string $endpoint): void
    {
        if (empty($packages) || !is_dir($workDir)) {
            return;
        }

        $packageFile = $workDir . '/package.json';
        if (!is_file($packageFile)) {
            return;
        }

        if (method_exists($this, 'snapshotFile')) {
            $this->snapshotFile($packageFile);
        }

        $package = json_decode((string)file_get_contents($packageFile), true);
        if (!is_array($package)) {
            return;
        }

        $removed = false;
        foreach (array_keys($packages) as $pkg) {
            foreach (['dependencies', 'devDependencies'] as $section) {
                if (isset($package[$section][$pkg])) {
                    unset($package[$section][$pkg]);
                    $removed = true;
                    $this->output("  ➖ {$endpoint}: Removed {$pkg}");
                }
            }
        }

        if ($removed) {
            @file_put_contents(
                $packageFile,
                json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
                LOCK_EX
            );
        }
    }

    /**
     * 补装插件依赖（供 CLI / 管理端重试入口调用）
     *
     * @return array{status: string, dependencies: array}
     */
    public function installDependencies(bool $force = true): array
    {
        $origin = $this->forceDependencyInstall;
        $this->forceDependencyInstall = $force;

        try {
            $this->installComposerDeps();
            $this->installNpmDeps();
        } finally {
            $this->forceDependencyInstall = $origin;
        }

        return [
            'status'       => $this->resolveOverallStatus(),
            'dependencies' => $this->dependencyState,
        ];
    }

    /**
     * 获取依赖执行器
     */
    protected function dependencyRunner(): DependencyRunner
    {
        $config = $this->getConfig('dependencies', []);
        $config = is_array($config) ? $config : [];

        return new DependencyRunner($config, function (string $message): void {
            $this->output($message);
        });
    }

    /**
     * 获取依赖归属登记表
     */
    protected function dependencyRegistry(): DependencyRegistry
    {
        return new DependencyRegistry();
    }

    /**
     * 记录依赖状态并持久化到插件 config/installed.php
     */
    protected function recordDependencyStatus(string $type, array $statuses): void
    {
        $this->dependencyState[$type] = $statuses;

        if (method_exists($this, 'saveDependencyState')) {
            $this->saveDependencyState([
                'status'              => $this->resolveOverallStatus(),
                'dependencies'        => $this->dependencyState,
                'added_dependencies'  => $this->addedDependencies,
            ]);
        }
    }

    /**
     * 构造统一状态结构
     */
    protected function buildStatus(array $packages, string $status, string $error = ''): array
    {
        $result = [];
        $now    = date('Y-m-d H:i:s');

        foreach ($packages as $package => $version) {
            $result[(string)$package] = [
                'status'     => $status,
                'version'    => (string)$version,
                'error'      => $error,
                'updated_at' => $now,
            ];
        }

        return $result;
    }

    /**
     * 汇总总体依赖状态：none / installed / pending / failed
     */
    protected function resolveOverallStatus(): string
    {
        $all = [];
        foreach ($this->dependencyState as $statuses) {
            foreach ($statuses as $item) {
                $all[] = $item['status'] ?? 'pending';
            }
        }

        if (empty($all)) {
            return 'none';
        }
        if (in_array('failed', $all, true)) {
            return 'failed';
        }
        if (in_array('pending', $all, true)) {
            return 'pending';
        }

        return 'installed';
    }

    /**
     * 输出依赖状态摘要与补装提示
     */
    protected function outputDependencySummary(): void
    {
        $status = $this->resolveOverallStatus();

        if ($status === 'none') {
            return;
        }

        $map = [
            'installed' => '✅ 依赖已安装',
            'pending'   => '⏳ 依赖待安装（声明已合并，需执行安装命令）',
            'failed'    => '❌ 依赖安装失败',
        ];

        $this->output("📊 依赖状态: " . ($map[$status] ?? $status));

        if (in_array($status, ['pending', 'failed'], true)) {
            $this->output("  💡 可在服务器执行补装: php webman madong-plugin:install-deps " . $this->getPluginName());
            $this->output("  💡 或配置 dependencies.auto_install=true 后重装插件；网络不通时可配置 dependencies.composer.mirror / dependencies.npm.registry");
        }
    }

    /**
     * 后端依赖安装命令描述（用于待执行清单）
     */
    protected function describeComposerInstall(array $packages): string
    {
        $bin      = (string)$this->getConfig('dependencies.composer.bin', 'composer');
        $segments = [];
        foreach (array_keys($packages) as $package) {
            $segments[] = escapeshellarg((string)$package);
        }

        return $bin . ' update --no-interaction --no-scripts ' . implode(' ', $segments);
    }

    /**
     * 执行 Shell 命令（保留兼容，委托给执行器）
     */
    protected function runShellCommand(string $command, string $workDir, string $label): void
    {
        $this->dependencyRunner()->run($command, $workDir, $label);
    }
}
