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
 * 模板资源操作 Trait
 */
trait TemplateTrait
{
    /** @var array<string> 模板复制失败的目标目录（用于安装标记 templates_pending） */
    protected array $templateCopyFailures = [];

    /**
     * 复制模板资源到目标目录
     *
     * 部署到两端：
     * - admin → template/admin/src/plugin/{name}
     * - web   → template/web/src/plugin/{name}
     */
    protected function copyTemplates(): void
    {
        // 先清理上次卸载/安装遗留的隔离目录（.trash-*）
        $this->purgeStaleTrashDirs();

        $templateDir = $this->pluginPath . '/resource/template';

        if (!is_dir($templateDir)) {
            $this->output("📂 No template directory found, skipping...");
            return;
        }

        $this->output("📂 Copying templates from {$templateDir}...");

        $this->copyTemplateDir('admin');
        $this->copyTemplateDir('web');
    }

    /**
     * 复制单个端的模板
     *
     * fail-soft：目录被 dev server（Vite watch）占用或处于 delete-pending
     * 幽灵状态时，带重试复制，仍失败仅警告，不抛异常中断安装流程。
     */
    protected function copyTemplateDir(string $endpoint): void
    {
        $resourceDir = $this->getConfig('resource.template', 'template');
        $targetBase = $this->getConfig("template.{$endpoint}");

        $sourceDir = $this->pluginPath . '/resource/' . $resourceDir . '/' . $endpoint;
        $targetDir = $this->getProjectRoot() . '/' . $targetBase . '/' . $this->pluginName;

        if (!is_dir($sourceDir)) {
            $this->output("  ⚠️ {$endpoint}: Template source not found");
            return;
        }

        if (!is_dir(dirname($targetDir))) {
            @mkdir(dirname($targetDir), 0755, true);
        }

        // 目标已存在（含 delete-pending 幽灵子目录）时先整体改名隔离腾出规范路径，
        // 再全新落盘——rename 不触碰子目录句柄，可绕开幽灵目录对 mkdir 的阻塞；
        // 隔离目录由 purgeStaleTrashDirs 在下次安装/卸载时自动清理
        if (is_dir($targetDir)) {
            if ($this->quarantineDir($targetDir)) {
                $this->output("  ♻️ {$endpoint}: 旧模板目录已隔离，全新部署: {$targetDir}");
            } else {
                // 改名失败（父目录被占用）时退回原策略：仅清理可移除的空/幽灵目录后合并复制
                $this->purgeGhostDir($targetDir);
            }
        }

        $this->recurseCopy($sourceDir, $targetDir);

        // 复制后按文件数校验：防止 fail-soft 静默吞掉落盘失败（如幽灵目录占用 mkdir）
        $sourceCount = $this->countFilesRecursive($sourceDir);
        $targetCount = $this->countFilesRecursive($targetDir);

        // web 端模板变化后主动重启 dev server，重新收集插件清单
        $this->restartWebDevServer($endpoint);

        if ($sourceCount > 0 && $targetCount >= $sourceCount) {
            // 记录安装创建的模板目录，便于安装失败时回滚清理
            if (method_exists($this, 'trackCreatedTemplate')) {
                $this->trackCreatedTemplate($targetDir);
            }

            $this->output("  ✅ {$endpoint}: Templates copied to {$targetDir}");
        } else {
            $this->templateCopyFailures[] = $targetDir;
            $this->output("  ⚠️ {$endpoint}: 模板未完全落盘（{$targetCount}/{$sourceCount} 个文件，目标可能被占用）已记录待补齐；关闭占用程序后再次执行安装即可自动补齐: {$targetDir}");
        }
    }

    /**
     * 确保 web dev server 加载最新插件清单（仅 web 端）
     *
     * 背景：nuxt.config.ts 的 collectPluginPlugins() 仅在 dev server 启动时
     * 收集各插件 plugins 目录生成虚拟模块；插件模板增删后缓存仍引用旧文件清单，
     * 运行中的 dev server 会对已删除文件报 import-analysis 错误导致全站白屏
     * （且 watcher 忽略 src/plugin 整个目录，文件增删不会自动触发重启）。
     *
     * 策略：运行中 → 结束整个进程树后重新拉起（分离进程、日志写入文件）；
     * 未运行 → 开发环境（存在 node_modules）直接拉起，保证操作后站点立即可访问，
     * 非开发环境跳过（生产服务器无 dev server 概念）。
     */
    protected function restartWebDevServer(string $endpoint): void
    {
        if ($endpoint !== 'web') {
            return;
        }

        $port = (int) $this->getConfig('template.web_dev_port', 3000);
        $webRoot = $this->getFrontendProjectPath('web');
        $logFile = base_path() . '/runtime/web-dev.log';

        // 定位 dev 端口监听进程
        $pids = [];
        if (PHP_OS_FAMILY === 'Windows') {
            $lines = [];
            @exec('netstat -ano | findstr ":' . $port . '" | findstr "LISTENING"', $lines);
            foreach ($lines as $line) {
                if (preg_match('/\s(\d+)\s*$/', trim($line), $m)) {
                    $pids[(int) $m[1]] = true;
                }
            }
        } else {
            $out = [];
            @exec('lsof -ti tcp:' . $port . ' 2>/dev/null', $out);
            foreach ($out ?: [] as $pid) {
                $pids[(int) $pid] = true;
            }
        }

        // 未运行：开发环境直接拉起（否则卸载/安装后前端不可访问）；
        // 静态守卫防止同一次安装/卸载的多轮模板复制重复拉起
        static $launched = false;
        if ($pids === []) {
            if (!$launched && is_dir($webRoot . DIRECTORY_SEPARATOR . 'node_modules')) {
                $launched = true;
                $this->launchWebDevServerProcess($webRoot, $logFile);
                $this->output("  ▶️ web: dev server 未运行，已启动（日志: {$logFile}）");
            } elseif (!$launched) {
                $this->output("  ℹ️ web: 端口 {$port} 无运行中的 dev server，跳过重启");
            }

            return;
        }

        // 结束进程树（npm→node→esbuild 等）
        foreach (array_keys($pids) as $pid) {
            if (PHP_OS_FAMILY === 'Windows') {
                @exec('taskkill /F /T /PID ' . (int) $pid);
            } else {
                @exec('kill -9 ' . (int) $pid);
            }
        }
        $this->output('  🛑 web: 已停止 dev server (pid ' . implode(',', array_keys($pids)) . ')');

        $launched = true;
        $this->launchWebDevServerProcess($webRoot, $logFile);
        $this->output("  🔄 web: dev server 重启中，日志: {$logFile}");
    }

    /**
     * 以分离进程拉起 web dev server（日志追加写入文件，不回显到当前终端）
     */
    protected function launchWebDevServerProcess(string $webRoot, string $logFile): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // 借助临时 bat 规避 start/cmd 多层引号转义问题；
            // 必须经 WMI Win32_Process.Create 启动——由 WMI 服务代为创建的进程
            // 彻底脱离调用方的 Job Object 与控制台生命周期：
            // - start /B + popen/exec：子进程附着父控制台/继承管道，PHP 退出被连带终止或永久挂起
            // - Start-Process：新隐藏控制台仍在调用方 Job Object 内，Job 关闭即被杀死
            $bat = sys_get_temp_dir() . '/madong-web-dev-restart.bat';
            @file_put_contents($bat, "@echo off\r\ncd /d \"{$webRoot}\"\r\nnpm run dev >> \"{$logFile}\" 2>&1\r\n");
            $cmdLine = 'cmd.exe /c ""' . $bat . '""';
            @exec('powershell -NoProfile -Command "Invoke-CimMethod -ClassName Win32_Process -MethodName Create -Arguments @{ CommandLine = \'' . $cmdLine . '\' }"');
        } else {
            @popen('cd "' . $webRoot . '" && nohup npm run dev >> "' . $logFile . '" 2>&1 &', 'r');
        }
    }

    /**
     * 是否存在模板复制失败
     */
    public function hasTemplateCopyFailures(): bool
    {
        return $this->templateCopyFailures !== [];
    }

    /**
     * 统计目录下文件总数（递归；幽灵目录无法读取时按 0 计）
     */
    protected function countFilesRecursive(string $dir): int
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return 0;
        }

        $count = 0;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                $count += $this->countFilesRecursive($path);
            } elseif (is_file($path)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 递归复制目录（容错版）
     *
     * Windows 下 dev server（Vite watch）持有句柄时 mkdir/copy 会失败
     * （delete-pending 幽灵目录），重试等待句柄释放；仍失败的仅记录警告。
     *
     * @return bool 是否全部复制成功
     */
    protected function recurseCopy(string $source, string $target, int $retry = 4, int $sleepUs = 500000): bool
    {
        if (!is_dir($source)) {
            return false;
        }

        // 空目录无需复制，避免向幽灵目标目录（delete-pending）做无意义的 mkdir 重试
        $entries = array_values(array_diff(scandir($source) ?: [], ['.', '..']));
        if ($entries === []) {
            return is_dir($target) || @mkdir($target, 0755, true) || is_dir($target);
        }

        $failed = [];

        for ($attempt = 0; $attempt <= $retry; $attempt++) {
            if ($attempt > 0) {
                usleep($sleepUs);
            }

            // 目录创建（递归），失败时等待句柄释放后重试
            if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
                $failed = [$target];
                continue;
            }

            $failed = [];
            foreach ($entries as $file) {
                $sourcePath = $source . '/' . $file;
                $targetPath = $target . '/' . $file;

                if (is_dir($sourcePath)) {
                    if (!$this->recurseCopy($sourcePath, $targetPath, $retry, $sleepUs)) {
                        $failed[] = $targetPath;
                    }
                } else {
                    if (!@copy($sourcePath, $targetPath) && !is_file($targetPath)) {
                        $failed[] = $targetPath;
                    }
                }
            }

            if ($failed === []) {
                return true;
            }
        }

        $this->output("  ⚠️ Copy failed (files/dirs may be locked by dev server):\n" . implode("\n", $failed));

        return false;
    }

    /**
     * 删除插件模板资源
     */
    protected function deleteTemplates(): void
    {
        $this->output("🗑️ Deleting plugin templates...");

        // 先清理上次遗留的隔离目录（.trash-*）
        $this->purgeStaleTrashDirs();

        $this->deleteTemplateDir('admin');
        $this->deleteTemplateDir('web');
    }

    /**
     * 删除单个端模板
     *
     * 删除失败（dev server 占用句柄）时尝试把残留目录改名隔离为
     * 同级 .trash-{name}-<ts>，腾出规范路径，避免 delete-pending
     * 幽灵目录阻塞下次安装；隔离目录在下次安装/卸载时自动清理。
     */
    protected function deleteTemplateDir(string $endpoint): void
    {
        $targetBase = $this->getConfig("template.{$endpoint}");

        $targetDir = $this->getProjectRoot() . '/' . $targetBase . '/' . $this->pluginName;

        if (!is_dir($targetDir)) {
            $this->output("  ⚠️ {$endpoint}: Template directory not found");
            return;
        }

        // 事务开启时改为移动到备份目录，卸载失败可原样移回
        if (method_exists($this, 'backupTemplateDir') && $this->backupTemplateDir($endpoint, $targetDir)) {
            $this->output("  ✅ {$endpoint}: Templates moved to backup (可回滚)");

            // rename 成功时原目录已不存在（is_dir 为 false，跳过）；
            // 复制降级备份时原目录仍在，必须删除，否则卸载后模板残留
            // （备份目录随事务提交被清理，残留的模板永远不会被清掉）
            if (is_dir($targetDir)) {
                $this->recurseDelete($targetDir);

                // 仍残留：dev server 持句柄通常为瞬态，等待释放后重试一轮
                if (is_dir($targetDir)) {
                    usleep(1500000);
                    $this->recurseDelete($targetDir, 3);
                }
            }

            $this->restartWebDevServer($endpoint);

            return;
        }

        $this->recurseDelete($targetDir);

        // 仍残留：dev server 持句柄通常为瞬态，等待释放后重试一轮
        if (is_dir($targetDir)) {
            usleep(1500000);
            $this->recurseDelete($targetDir, 3);
        }

        $this->restartWebDevServer($endpoint);

        if (!is_dir($targetDir)) {
            $this->output("  ✅ {$endpoint}: Templates deleted");
            return;
        }

        // 仍残留：改名隔离腾出规范路径；rename 在句柄释放的瞬间即可成功，故多次尝试
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($this->quarantineDir($targetDir)) {
                $this->output("  ♻️ {$endpoint}: 残留模板目录已隔离，将在下次安装/卸载时自动清理");
                $this->restartWebDevServer($endpoint);
                return;
            }
            usleep(500000);
        }

        // 隔离也失败（前端 dev watcher 持续持有目录句柄，目录进入 delete-pending 幽灵态）：
        // 运行期任何同步/延时重试都无法对抗持续句柄，只能等 dev server 重启释放后由系统自动完成挂起删除；
        // 此处仍启动分离延时清理（对无 dev server 的环境生效），并提示用户重启前端即自动清零
        $this->scheduleDelayedEmptyDirCleanup($targetDir);
        $this->restartWebDevServer($endpoint);
        $this->output("  ⚠️ {$endpoint}: 少量空目录被前端 dev server 持续占用（delete-pending 幽灵态，0 文件、无害）；重启前端 dev 后系统自动完成清理，下次安装也会自动腾出路径: {$targetDir}");
    }

    /**
     * 启动分离的后台进程延时清理残留空目录树
     *
     * 背景：Windows 下前端 dev server（Vite/Nuxt granular watcher）运行期卸载，
     * 其轮询扫描与递归删除持续竞争目录句柄，同步重试窗口（约 3s）内难以全部释放，
     * 目录进入 delete-pending 幽灵态。分离进程等待事件冷却后按轮清理，成功率高；
     * 仅当整棵树为空时才删（重装写入内容即放弃），零误删风险。10 轮后仍失败则静默退出，
     * 规范路径已被腾出逻辑兜底（下次安装 swap 自动清）。
     */
    protected function scheduleDelayedEmptyDirCleanup(string $targetDir): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || !is_dir($targetDir)) {
            return;
        }

        $payload = base64_encode((string) json_encode([$targetDir]));

        $code = <<<'PHP'
$dirs = json_decode(base64_decode($argv[1] ?? ''), true) ?: [];
$delTree = function (string $d) use (&$delTree): bool {
    $entries = @scandir($d);
    if ($entries === false) {
        return false; // delete-pending 幽灵态，等下一轮
    }
    foreach (array_diff($entries, ['.', '..']) as $e) {
        $p = $d . DIRECTORY_SEPARATOR . $e;
        if (is_dir($p)) {
            if (!$delTree($p)) {
                return false;
            }
        } elseif (!@unlink($p) && !@rmdir($p)) {
            // is_dir 为 false 的也可能是句柄挂起中的目录（恢复后即目录）：
            // unlink（文件/断链）与 rmdir（幽灵目录）都试，双失败才放弃
            return false;
        }
    }
    return @rmdir($d);
};
for ($i = 0; $i < 15; $i++) {
    $left = 0;
    foreach ($dirs as $dir) {
        if (is_dir($dir) && !$delTree($dir)) {
            $left++;
        }
    }
    if ($left === 0) {
        exit(0);
    }
    sleep(2);
}
PHP;

        // start /B 首个引号参数为窗口标题，须占位空标题；base64 以单引号包裹（PHP 字面量 + cmd 内字面量）
        $cmd = 'start /B "" php -r "eval(base64_decode(\''
            . base64_encode($code)
            . '\'));" '
            . $payload;
        @popen($cmd, 'r');
    }

    /**
     * 清理幽灵目录（delete-pending 空目录）
     *
     * 目录存在但为空且无法写入时，Windows 通常处于删除挂起状态，
     * 等待句柄释放后 rmdir 即可移除，释放规范路径供重建。
     */
    protected function purgeGhostDir(string $dir, int $retry = 3): void
    {
        for ($attempt = 0; $attempt <= $retry && is_dir($dir); $attempt++) {
            $entries = array_diff(@scandir($dir) ?: [], ['.', '..']);
            if ($entries !== []) {
                return; // 非空目录不处理（由 recurseCopy 合并/更新）
            }

            if (@rmdir($dir)) {
                return;
            }

            usleep(300000);
        }
    }

    /**
     * 把无法删除的残留目录改名隔离为同级 .trash-{name}-<ts>
     *
     * @return bool 隔离成功返回 true
     */
    protected function quarantineDir(string $dir): bool
    {
        $name = $this->pluginName ?: 'plugin';
        $target = dirname($dir) . DIRECTORY_SEPARATOR . '.trash-' . $name . '-' . date('YmdHis') . '-' . substr((string)mt_rand(), 0, 4);

        return @rename($dir, $target) && is_dir($target);
    }

    /**
     * 清理历史遗留的隔离目录（.trash-{pluginName}-*）
     *
     * 在每次安装/卸载模板操作前调用，句柄已释放时即可彻底清除。
     */
    protected function purgeStaleTrashDirs(): void
    {
        $name = $this->pluginName ?: '';
        if ($name === '') {
            return;
        }

        foreach (['admin', 'web'] as $endpoint) {
            $base = $this->getConfig("template.{$endpoint}");
            if (empty($base)) {
                continue;
            }

            $parentDir = $this->getProjectRoot() . '/' . $base;
            if (!is_dir($parentDir)) {
                continue;
            }

            foreach (glob($parentDir . '/.trash-' . $name . '-*', GLOB_ONLYDIR) ?: [] as $trashDir) {
                $this->recurseDelete($trashDir, 1);
                if (!is_dir($trashDir)) {
                    $this->output("  🧹 Cleaned stale trash dir: {$trashDir}");
                }
            }
        }
    }

    /**
     * 递归删除目录（容错版）
     *
     * Windows 下 dev server（Vite watch）持有文件句柄时 unlink 会报 Permission denied，
     * 重试等待句柄释放；仍失败的仅记录警告，不抛异常、不中断卸载流程。
     */
    protected function recurseDelete(string $dir, int $retry = 2): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        $failed = [];

        for ($attempt = 0; $attempt <= $retry; $attempt++) {
            $failed = [];

            $files = @scandir($dir);
            if ($files === false) {
                return false;
            }

            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $path = $dir . '/' . $file;

                if (@filetype($path) === false) {
                    // 幽灵条目（delete-pending，lstat 失败），本轮跳过等待句柄释放
                    $failed[] = $path;
                    continue;
                }

                if (@is_dir($path)) {
                    $this->recurseDelete($path, $retry) || $failed[] = $path;
                } else {
                    @unlink($path) || $failed[] = $path;
                }
            }

            if (@rmdir($dir)) {
                return true;
            }

            if ($attempt < $retry) {
                usleep(300000);
            }
        }

        $this->output("  ⚠️ Delete failed (files may be locked):\n" . implode("\n", $failed));

        return false;
    }

    /**
     * 导入模板（子类可重写）
     */
    protected function importTemplates(): void
    {
        $this->copyTemplates();
    }

    /**
     * 获取项目根目录
     */
    protected function getProjectRoot(): string
    {
        return dirname(base_path());
    }

    /**
     * 获取前端基础目录
     * 前端代码统一在 template 目录下
     */
    protected function getFrontendPath(): string
    {
        return $this->getProjectRoot() . DIRECTORY_SEPARATOR . 'template';
    }

    /**
     * 获取前端指定项目目录
     *
     * @param string $frontendType 前端类型（admin/web/uni-app）
     * @return string 前端项目目录
     */
    protected function getFrontendProjectPath(string $frontendType): string
    {
        return $this->getFrontendPath() . DIRECTORY_SEPARATOR . $frontendType;
    }
}
