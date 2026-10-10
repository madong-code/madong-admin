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

namespace app\command\plugin;

use app\command\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * 插件传输命令
 *
 * 辅助开发者将插件提取到 download/{name}（对接私有仓库），
 * 或从 download 分发到项目中的后端和前端目录。
 *
 * 整个插件即一个仓库，resource/template/{admin|web} 中的资源
 * 与远程仓库结构完全对应。
 *
 * ┌─ 命令集合 ───────────────────────────────────────────┐
 * │ 1. 路径常量    —— 各端路径定义，按需修改               │
 * │ 2. 属性        —— 运行时路径变量                      │
 * │ 3. 命令配置    —— configure() 参数/选项定义            │
 * │ 4. 入口        —— __invoke() 场景路由                 │
 * │ 5. 初始化      —— initPaths() 路径初始化              │
 * │ 6. 场景执行    —— 四个场景：backend / full / extract / resource │
 * │ 7. 工具方法    —— 递归拷贝 / 文件统计 / 结果汇总      │
 * └──────────────────────────────────────────────────────┘
 *
 * ┌─ 使用示例 ───────────────────────────────────────────┐
 * │ php webman madong-plugin:transfer demo backend       │ 部署后端
 * │ php webman madong-plugin:transfer demo full          │ 完整部署三端
 * │ php webman madong-plugin:transfer demo extract       │ 提取到download
 * │ php webman madong-plugin:transfer demo resource      │ 前端模板回写插件resource
 * └──────────────────────────────────────────────────────┘
 *
 * ┌─ 额外前端端点（h5/uniapp 等）────────────────────────┐
 * │ resource / full 场景默认只处理 admin/web 两个内置端； │
 * │ 插件自带独立前端时，在插件 config/info.php 中声明：    │
 * │   'template' => ['cms' => 'template/cms/src/plugin'] │
 * │ （端点名 => 项目内路径前缀，与框架 appConfig 及        │
 * │  Install.php resolveExtraTarget() 同一约定，          │
 * │  缺省 template/{endpoint}/src/plugin）                │
 * │ 命令按 {前缀}/{插件名} 识别源目录并同步：              │
 * │   resource: template/cms/src/plugin/{name}            │
 * │           → backend/plugin/{name}/resource/template/{endpoint} │
 * │   full:    download/{name}/resource/template/{endpoint}│
 * │           → template/cms/src/plugin/{name}            │
 * │ 注意：仅同步插件子树，子树外文件（pages.json 注册、    │
 * │ locale 等）不参与同步，与 admin/web 既有边界一致。     │
 * └──────────────────────────────────────────────────────┘
 *
 * @author Mr.April
 * @since 1.0.0
 */
#[AsCommand(
    name: 'madong-plugin:transfer',
    description: 'Transfer plugin between project and download package',
    aliases: ['madong-plugin:transfer'],
    hidden: false
)]
class TransferCommand extends BaseCommand
{
    // ═══════════════ 1. 路径常量（按需修改） ═══════════════
    // 注意：整个插件即一个仓库，resource/template/{admin|web}
    // 中的资源结构与远程仓库完全对应。修改路径时请确保各端一致。

    private const DOWNLOAD_DIR = '/download';                              // 下载包存放目录（项目根目录）
    private const BACKEND_DIR = '/backend/plugin';                      // 后端插件目录
    private const ADMIN_DIR = '/template/admin/src/plugin';             // Admin前端插件目录
    private const WEB_DIR = '/template/web/src/plugin';                 // Web前端插件目录
    private const DL_ADMIN_TMPL = '/resource/template/admin';           // 下载包内admin模板路径
    private const DL_WEB_TMPL = '/resource/template/web';               // 下载包内web模板路径
    private const PLUGIN_RES_TMPL = '/resource/template';               // 插件内前端模板根路径

    // ═══════════════════ 2. 属性 ═══════════════════════════

    /** 项目根目录（backend 的父目录） */
    private string $projectRoot;

    /** 下载包源目录 */
    private string $sourceDir;

    /** 后端插件目录 */
    private string $backendDir;

    /** Admin 前端插件目录 */
    private string $adminDir;

    /** Web 前端插件目录 */
    private string $webDir;

    /** 下载包内的 admin 模板目录 */
    private string $dlAdminTmpl;

    /** 下载包内的 web 模板目录 */
    private string $dlWebTmpl;

    /** info.php 声明的额外前端端点（端点名 => 项目内路径前缀） */
    private array $extraEndpoints = [];

    /** 插件内模板资源根（info.php resource.template，缺省 template） */
    private string $resourceBase = 'template';

    // ═══════════════ 3. 命令配置 ═══════════════════════════

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Plugin name (e.g. demo)')
            ->addArgument('scenario', InputArgument::REQUIRED, 'Scenario: backend, full, extract, resource')
            ->addOption('source', 's', InputOption::VALUE_OPTIONAL, 'Custom source directory', null)
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force execution in playground/demo environment');
    }

    // ═════════════════ 4. 入口 ═════════════════════════════

    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Plugin Transfer');

        $pluginName = $input->getArgument('name');
        $scenario = $input->getArgument('scenario');
        $customSource = $input->getOption('source');
        $force = (bool) $input->getOption('force');

        // 验证场景
        $validScenarios = ['backend', 'full', 'extract', 'resource'];
        if (!in_array($scenario, $validScenarios)) {
            return $this->outputError($io, "Invalid scenario: '{$scenario}'. Allowed: backend, full, extract, resource");
        }

        // 演示/沙盒环境限制：所有场景都会写文件系统，默认禁止执行（除非 --force）
        if ($this->isPlaygroundEnabled() && !$force) {
            return $this->outputError(
                $io,
                sprintf(
                    "%s\n%s",
                    config('playground.message', '演示环境,不支持当前操作'),
                    'This command modifies the filesystem. Use --force to bypass in playground environment.'
                )
            );
        }

        // 初始化路径
        $this->initPaths($pluginName, $customSource);

        // 读取 info.php 的额外端点声明（无声明时仅处理 admin/web）
        $this->loadExtraEndpoints($io, $pluginName);

        $scenarioLabels = ['backend' => 'Deploy backend only', 'full' => 'Full deploy to all', 'extract' => 'Extract to download', 'resource' => 'Sync frontend to plugin resource'];
        $io->info(sprintf('Plugin: %s', $pluginName));
        $io->info(sprintf('Scenario: %s (%s)', $scenario, $scenarioLabels[$scenario]));
        $io->info(sprintf('Source: %s', $this->sourceDir));
        $io->newLine();

        if ($scenario === 'extract') {
            return $this->scenarioExtract($io, $pluginName);
        }

        if ($scenario === 'resource') {
            return $this->scenarioResource($io, $pluginName);
        }

        // backend/full：检查源目录
        if (!is_dir($this->sourceDir)) {
            return $this->outputError($io, sprintf(
                "Source directory not found: %s\nPlease place the downloaded plugin package in download/%s/",
                $this->sourceDir,
                $pluginName
            ));
        }

        if ($scenario === 'backend') {
            return $this->scenarioBackendOnly($io, $pluginName);
        }

        return $this->scenarioFull($io, $pluginName);
    }

    // ═════════════════ 5. 初始化 ═══════════════════════════

    /**
     * 初始化路径
     */
    private function initPaths(string $pluginName, ?string $customSource): void
    {
        $this->projectRoot = rtrim(dirname(base_path()), '/\\');

        $this->sourceDir = $customSource
            ? rtrim($customSource, '/\\')
            : $this->projectRoot . self::DOWNLOAD_DIR . '/' . $pluginName;
        $this->backendDir = $this->projectRoot . self::BACKEND_DIR . '/' . $pluginName;
        $this->adminDir = $this->projectRoot . self::ADMIN_DIR . '/' . $pluginName;
        $this->webDir = $this->projectRoot . self::WEB_DIR . '/' . $pluginName;
        $this->dlAdminTmpl = $this->sourceDir . self::DL_ADMIN_TMPL;
        $this->dlWebTmpl = $this->sourceDir . self::DL_WEB_TMPL;
    }

    /**
     * 读取插件 info.php 的额外前端端点声明与资源根
     *
     * 约定与框架 appConfig（core/business/config/plugin.php）及
     * Install.php resolveExtraTarget() 同构：
     *   'template' => ['cms' => 'template/cms/src/plugin']  // 端点名 => 项目内路径前缀
     *   'resource' => ['template' => 'template']            // 插件内资源根（缺省 template）
     *
     * 内置端 admin/web 由本命令默认处理，声明中忽略；
     * 异常声明逐项 warning 跳过，不中断。
     */
    private function loadExtraEndpoints(SymfonyStyle $io, string $pluginName): void
    {
        $this->extraEndpoints = [];
        $this->resourceBase = 'template';

        $infoFile = $this->backendDir . '/config/info.php';
        if (!is_file($infoFile)) {
            return;
        }

        $loaded = @include $infoFile;
        if (!is_array($loaded)) {
            $io->warning(sprintf('Invalid plugin info file (not an array), extra endpoints ignored: %s', $infoFile));
            return;
        }

        // 插件内资源根（与 Install.php 的 resourceBase 一致）
        $resourceBase = $loaded['resource']['template'] ?? null;
        if (is_string($resourceBase) && $resourceBase !== '' && !str_contains($resourceBase, '..')) {
            $this->resourceBase = rtrim($resourceBase, '/\\');
        }

        // 额外端点声明
        $declared = $loaded['template'] ?? null;
        if ($declared === null) {
            return;
        }
        if (!is_array($declared)) {
            $io->warning(sprintf(
                "Plugin info 'template' must be an array (endpoint => path prefix), got %s. Extra endpoints ignored.",
                gettype($declared)
            ));
            return;
        }

        foreach ($declared as $endpoint => $pathBase) {
            if (!is_string($endpoint) || !preg_match('/^[A-Za-z0-9_-]+$/', $endpoint)) {
                $io->warning(sprintf('Invalid endpoint name in info.php template declaration, skipped: %s', var_export($endpoint, true)));
                continue;
            }
            if (in_array(strtolower($endpoint), ['admin', 'web'], true)) {
                $io->writeln(sprintf('  <comment>ℹ Endpoint "%s" is handled by default, declaration ignored.</comment>', $endpoint));
                continue;
            }
            if (!is_string($pathBase) || $pathBase === '') {
                $pathBase = 'template/' . $endpoint . '/src/plugin';
            }
            $pathBase = rtrim(str_replace('\\', '/', $pathBase), '/');
            if (str_starts_with($pathBase, '/') || preg_match('#^[A-Za-z]:#', $pathBase) || str_contains($pathBase, '..')) {
                $io->warning(sprintf('Unsafe path for endpoint "%s" in info.php template declaration, skipped: %s', $endpoint, $pathBase));
                continue;
            }
            $this->extraEndpoints[$endpoint] = $pathBase;
        }
    }

    // ═══════════════ 6. 场景执行 ═══════════════════════════

    /**
     * 场景 backend：仅部署到后端
     *
     * 从 download 拷贝到 backend/plugin/{name}，前端模板由 Install.php
     * 的 afterInstall() 在安装时自动分发。
     */
    private function scenarioBackendOnly(SymfonyStyle $io, string $pluginName): int
    {
        $io->section('Scenario: Deploy to Backend Only');
        $io->writeln('  <comment>Note: The backend Install.php will distribute frontend</comment>');
        $io->writeln('  <comment>      templates from resource/template/ during afterInstall()</comment>');
        $io->newLine();

        $this->copyDirectory($io, $this->sourceDir, $this->backendDir, 'backend');

        $io->newLine();
        $io->success(sprintf("Plugin '%s' deployed to backend/plugin/", $pluginName));
        $io->writeln('  <comment>Next: Install via plugin management in admin panel, or run the PHP install command</comment>');

        return self::SUCCESS;
    }

    /**
     * 场景 full：完整部署到后端 + 前端
     *
     * 从 download 分发到三端目录：
     *   download/{name}/                        → backend/plugin/{name}/
     *   download/{name}/resource/template/admin/  → admin 前端插件目录
     *   download/{name}/resource/template/web/    → web 前端插件目录
     */
    private function scenarioFull(SymfonyStyle $io, string $pluginName): int
    {
        $io->section('Scenario: Full Deploy to All');

        // 1. download → 后端
        $this->copyDirectory($io, $this->sourceDir, $this->backendDir, 'Backend');
        $io->newLine();

        // 2. download/resource/template/admin → Admin 前端
        if (is_dir($this->dlAdminTmpl)) {
            $this->copyDirectory($io, $this->dlAdminTmpl, $this->adminDir, 'Admin frontend');
        } else {
            $io->warning(sprintf('No admin template in download, skipped: %s', $this->dlAdminTmpl));
        }
        $io->newLine();

        // 3. download/resource/template/web → Web 前端
        if (is_dir($this->dlWebTmpl)) {
            $this->copyDirectory($io, $this->dlWebTmpl, $this->webDir, 'Web frontend');
        } else {
            $io->warning(sprintf('No web template in download, skipped: %s', $this->dlWebTmpl));
        }
        $io->newLine();

        // 4. 额外端点：download/resource/{resourceBase}/{endpoint} → 声明路径（对称于 admin/web）
        foreach ($this->extraEndpoints as $endpoint => $pathBase) {
            $source = $this->sourceDir . '/resource/' . $this->resourceBase . '/' . $endpoint;
            if (is_dir($source)) {
                $target = $this->projectRoot . '/' . $pathBase . '/' . $pluginName;
                $this->copyDirectory($io, $source, $target, ucfirst($endpoint) . ' frontend');
            } else {
                $io->warning(sprintf('No %s template in download, skipped: %s', $endpoint, $source));
            }
            $io->newLine();
        }

        // 结果汇总
        $this->printSummary($io);

        $io->newLine();
        $io->success(sprintf("Plugin '%s' deployed to all locations!", $pluginName));
        $io->writeln('  <comment>Files are ready. Run git add + git commit manually.</comment>');

        return self::SUCCESS;
    }

    /**
     * 场景 extract：从项目中提取插件到 download
     *
     * 逆向收集三端代码到 download/{name}/，用于提交到私有仓库：
     *   backend/plugin/{name}/                → download/{name}/
     *   admin 前端插件目录                     → download/{name}/resource/template/admin/
     *   web 前端插件目录                       → download/{name}/resource/template/web/
     */
    private function scenarioExtract(SymfonyStyle $io, string $pluginName): int
    {
        $io->section('Scenario: Extract Plugin from Project to Download');

        // 1. 后端 → download（先清理残留，再全量拷贝）
        if (is_dir($this->backendDir)) {
            $this->copyDirectory($io, $this->backendDir, $this->sourceDir, 'Backend → download', clean: true);
        } else {
            $io->warning(sprintf('Backend plugin not found: %s', $this->backendDir));
        }
        $io->newLine();

        // 1.1 额外端点资源完整性检查：插件 resource/{resourceBase}/{endpoint} 随后端整包带出，
        //     缺失说明插件仓库未做 resource 回写，静默导出会产生不完整交付包
        foreach ($this->extraEndpoints as $endpoint => $pathBase) {
            $resourceDir = $this->backendDir . '/resource/' . $this->resourceBase . '/' . $endpoint;
            if (!is_dir($resourceDir)) {
                $io->warning(sprintf(
                    'Extra endpoint "%s" resource missing in plugin repo: %s — run "madong-plugin:transfer %s resource" first, otherwise the package cannot deploy this endpoint.',
                    $endpoint,
                    $resourceDir,
                    $pluginName
                ));
            }
        }

        // 2. Admin 前端 → download/resource/template/admin
        if (is_dir($this->adminDir)) {
            $this->copyDirectory($io, $this->adminDir, $this->dlAdminTmpl, 'Admin frontend → download/template', clean: true);
        } else {
            $io->warning(sprintf('Admin frontend not found, skipped: %s', $this->adminDir));
        }
        $io->newLine();

        // 3. Web 前端 → download/resource/template/web
        if (is_dir($this->webDir)) {
            $this->copyDirectory($io, $this->webDir, $this->dlWebTmpl, 'Web frontend → download/template', clean: true);
        } else {
            $io->warning(sprintf('Web frontend not found, skipped: %s', $this->webDir));
        }
        $io->newLine();

        // 结果汇总
        $io->success(sprintf("Plugin '%s' extracted to download/%s/", $pluginName, $pluginName));
        $io->writeln('  <comment>Download package is ready for private repo submission.</comment>');

        return self::SUCCESS;
    }

    /**
     * 场景 resource：开发时把前端模板回写到插件 resource
     *
     * 开发期前端代码在 template/{admin|web}/src/plugin/{name} 中维护，
     * 该场景将其逆向同步回 backend/plugin/{name}/resource/template/{admin|web}，
     * 保持插件仓库（安装分发源）与前端实际代码一致：
     *   template/admin/src/plugin/{name}/ → backend/plugin/{name}/resource/template/admin/
     *   template/web/src/plugin/{name}/   → backend/plugin/{name}/resource/template/web/
     */
    private function scenarioResource(SymfonyStyle $io, string $pluginName): int
    {
        $io->section('Scenario: Sync Frontend Templates to Plugin Resource');

        // 插件仓库必须已存在于后端（resource 是其子目录）
        if (!is_dir($this->backendDir)) {
            return $this->outputError($io, sprintf('Backend plugin not found: %s', $this->backendDir));
        }

        $hasAny = false;

        // 1. Admin 前端 → 插件 resource/template/admin（先清理残留再拷贝）
        if (is_dir($this->adminDir)) {
            $this->copyDirectory($io, $this->adminDir, $this->backendDir . self::PLUGIN_RES_TMPL . '/admin', 'Admin frontend → plugin resource', clean: true);
            $hasAny = true;
        } else {
            $io->warning(sprintf('No admin frontend in project, skipped: %s', $this->adminDir));
        }
        $io->newLine();

        // 2. Web 前端 → 插件 resource/template/web
        if (is_dir($this->webDir)) {
            $this->copyDirectory($io, $this->webDir, $this->backendDir . self::PLUGIN_RES_TMPL . '/web', 'Web frontend → plugin resource', clean: true);
            $hasAny = true;
        } else {
            $io->warning(sprintf('No web frontend in project, skipped: %s', $this->webDir));
        }
        $io->newLine();

        // 3. 额外端点：声明路径/{pluginName} → 插件 resource/{resourceBase}/{endpoint}
        foreach ($this->extraEndpoints as $endpoint => $pathBase) {
            $source = $this->projectRoot . '/' . $pathBase . '/' . $pluginName;
            if (is_dir($source)) {
                $this->copyDirectory($io, $source, $this->backendDir . '/resource/' . $this->resourceBase . '/' . $endpoint, ucfirst($endpoint) . ' frontend → plugin resource', clean: true);
                $hasAny = true;
            } else {
                $io->warning(sprintf('No %s frontend in project, skipped: %s', $endpoint, $source));
            }
            $io->newLine();
        }

        if (!$hasAny) {
            return $this->outputError($io, sprintf('No frontend plugin directories found for plugin: %s', $pluginName));
        }

        $io->success(sprintf("Plugin '%s' frontend templates synced to resource/template/!", $pluginName));
        $io->writeln('  <comment>Plugin repo is now in sync with the live frontend code.</comment>');

        return self::SUCCESS;
    }

    // ═══════════════ 7. 工具方法 ═══════════════════════════

    /**
     * 检查 Playground（演示/沙盒）环境是否开启
     *
     * 与 app/middleware/traits/PlaygroundTrait.php 共用同一配置项，
     * 配置见 config/playground.php（enable=true 时开启演示限制）。
     *
     * @return bool
     */
    private function isPlaygroundEnabled(): bool
    {
        return config('playground.enable', false) === true;
    }

    /**
     * 递归拷贝目录内容
     *
     * @param bool $clean 是否先清理目标目录再拷贝（避免残留旧文件）
     */
    private function copyDirectory(SymfonyStyle $io, string $source, string $dest, string $label, bool $clean = false): void
    {
        if (!is_dir($source)) {
            return;
        }

        // 清理模式：删除目标目录中除 .git 外的所有内容（保留 git 仓库）
        if ($clean && is_dir($dest)) {
            $this->cleanDirectoryExceptGit($dest);
            $io->writeln('  <comment>🧹 Cleaned target directory (kept .git)</comment>');
        }

        // 统计文件数
        $fileCount = $this->countFiles($source);

        $io->writeln(sprintf('  <info>↻</info> %s', $label));
        $io->writeln(sprintf('    <comment>From:</comment> %s', $source));
        $io->writeln(sprintf('    <comment>To:</comment>   %s', $dest));
        $io->writeln(sprintf('    <comment>Files:</comment> %d', $fileCount));

        // 创建目标目录
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        // 逐个拷贝（跳过 .git 目录）
        $source = rtrim($source, '/\\');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $copied = 0;
        foreach ($iterator as $item) {
            // 计算相对路径（基于源目录前缀剥离）
            $relativePath = substr($item->getPathname(), strlen($source) + 1);
            $relativePath = str_replace('\\', '/', $relativePath);
            // 跳过 .git 目录及其内部所有文件
            if (str_starts_with($relativePath, '.git/') || $relativePath === '.git') {
                continue;
            }

            $targetPath = $dest . '/' . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }
                copy($item->getPathname(), $targetPath);
                $copied++;
            }
        }

        $io->writeln(sprintf('    <info>✓ Copied %d files</info>', $copied));
    }

    /**
     * 删除目录下除 .git 外的所有文件/子目录
     *
     * 用于 extract 场景的清理模式，保留目标目录的 git 仓库元数据。
     */
    private function cleanDirectoryExceptGit(string $dir): void
    {
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === '.git') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
    }

    /**
     * 统计目录下文件数
     */
    private function countFiles(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 打印结果汇总
     */
    private function printSummary(SymfonyStyle $io): void
    {
        $summary = [
            ['Backend', $this->backendDir],
            ['Admin FE', $this->adminDir],
            ['Web FE', $this->webDir],
        ];

        // 额外端点按声明路径展示
        foreach ($this->extraEndpoints as $endpoint => $pathBase) {
            $summary[] = [ucfirst($endpoint) . ' FE', $this->projectRoot . '/' . $pathBase];
        }

        $rows = [];
        foreach ($summary as [$label, $path]) {
            $cnt = $this->countFiles($path);
            $status = $cnt > 0 ? sprintf('<info>✔ %d files</info>', $cnt) : '<comment>○ empty</comment>';
            $rows[] = [$label, $path, $status];
        }

        $io->table(
            ['Location', 'Path', 'Status'],
            $rows
        );
    }
}
