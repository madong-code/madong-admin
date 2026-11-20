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

namespace app\command\install;

use app\command\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * 下载前端模板资源（标准版：separated 结构）
 *
 * 目录结构（与框架 core/business/install、config/terminal.php 约定一致）：
 *  - template/admin：管理端（single/aura 来源统一重命名到此目录）
 *  - template/web  ：会员端
 *  - skills        ：技能模板（可选，-s 附加下载）
 *
 * 下载配置与 saas 版 config/install.php 的 download 段保持同构：
 *  - 默认 ZIP 归档下载，--method=git 可切回克隆
 *  - 默认 Gitee 国内镜像，缺失仓库按 fallback_mirrors 顺序回退
 *  - admin 支持 single/aura 两种 UI 来源，均统一落到 template/admin
 *
 * 使用方法：
 *   php webman madong-download:template            # 下载 admin + web 模板
 *   php webman madong-download:template -a          # 仅下载 admin 模板
 *   php webman madong-download:template -w          # 仅下载 web 模板
 *   php webman madong-download:template -s          # 额外下载 skills
 *   php webman madong-download:template --admin-variant=aura  # admin 来源选 aura，仍落到 template/admin
 *   php webman madong-download:template --mirror=gitee        # 切换下载源（默认 Github）
 *   php webman madong-download:template --method=git          # 切回 Git 克隆
 *   php webman madong-download:template -b develop  # 指定分支
 *   php webman madong-download:template -f          # 目录已存在时强制更新
 *
 * @author Mr.April
 * @since 1.0.0
 */
#[AsCommand(
    name: 'madong-download:template',
    description: 'Download template code to template directory',
    aliases: ['madong-download:template'],
    hidden: false
)]
class DownloadCommand extends BaseCommand
{
    /** admin 模板统一落地目录（single/aura 来源均重命名到此） */
    private const ADMIN_DIR = 'template/admin';

    /**
     * 下载配置
     */
    private array $downloadConfig = [
        'default_branch' => 'main',
        // 默认 ZIP 归档下载，不依赖 Git 与本地代理；--method=git 可切回克隆
        'method' => 'zip',
        'archive_templates' => [
            'github.com'  => 'https://github.com/{path}/archive/refs/heads/{branch}.zip',
            'gitee.com'   => 'https://gitee.com/{path}/repository/archive/{branch}.zip',
            'gitcode.com' => 'https://gitcode.com/{path}/archive/{branch}.zip',
        ],
        // 默认 GitHub；缺失的仓库按 fallback_mirrors 顺序回退
        'mirror' => 'github',
        'fallback_mirrors' => ['gitee','gitcode'],
        'mirrors' => [
            'github' => [
                'label' => 'GitHub 官方源',
                'repositories' => [
                    'single' => 'https://github.com/madong-code/madong-single.git',
                    'aura'   => 'https://github.com/madong-code/madong-aura.git',
                    'web'    => 'https://github.com/madong-code/web-nuxt.git',
                    'skills' => 'https://github.com/madong-code/madong-admin-saas-skills.git',
                ],
            ],
            'gitee' => [
                'label' => 'Gitee 国内镜像',
                'no_proxy' => true,
                'repositories' => [
                    'single' => 'https://gitee.com/motion-code/madong-single.git',
                    'aura'   => 'https://gitee.com/motion-code/madong-aura.git',
                    'web'    => 'https://gitee.com/motion-code/web-nuxt.git',
                    'skills' => 'https://gitee.com/motion-code/madong-admin-saas-skills.git',
                ],
            ],
            'gitcode' => [
                'label' => 'GitCode 国内镜像',
                'no_proxy' => true,
                'repositories' => [
                    'single' => 'https://gitcode.com/motion-code/madong-single.git',
                    'aura'   => 'https://gitcode.com/motion-code/madong-aura.git',
                    'web'    => 'https://gitcode.com/motion-code/web-nuxt.git',
                    'skills' => 'https://gitcode.com/motion-code/madong-admin-saas-skills.git',
                ],
            ],
        ],
        // admin 默认 UI 来源；--admin-variant 可覆盖
        'admin_variant' => 'single',
        'admin_variants' => [
            'single' => ['label' => '管理端（single）', 'repo' => 'single'],
            'aura'   => ['label' => '管理端（aura）',   'repo' => 'aura'],
        ],
        // 模板 => 物理路径（separated 结构）
        'templates' => [
            'admin' => self::ADMIN_DIR,
            'web'   => 'template/web',
        ],
        // 可选附加资源
        'extra' => [
            'skills' => 'skills',
        ],
    ];

    /** 国内镜像直连时用于忽略本机失效代理的 git -c 参数 */
    private string $gitConfigOptions = '';

    protected function configure(): void
    {
        $this
            ->addOption('admin', 'a', InputOption::VALUE_NONE, 'Download admin template (template/admin)')
            ->addOption('web', 'w', InputOption::VALUE_NONE, 'Download web template (template/web)')
            ->addOption('skills', 's', InputOption::VALUE_NONE, 'Additionally download skills template (skills/)')
            ->addOption('admin-variant', null, InputOption::VALUE_REQUIRED, 'Admin UI source: single/aura; both land in template/admin')
            ->addOption('mirror', null, InputOption::VALUE_REQUIRED, 'Download source: gitee (default) / gitcode / github')
            ->addOption('method', null, InputOption::VALUE_REQUIRED, 'Download method: zip (default) / git')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force update (overwrite existing)')
            ->addOption('branch', 'b', InputOption::VALUE_REQUIRED, 'Git branch to download', 'main');
    }

    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Template Downloader');

        $downloadConfig = $this->downloadConfig;

        // 下载方式：zip（默认）/ git
        $method = $this->stringValue($input->getOption('method')) ?? $downloadConfig['method'];
        if ($method === 'git' && !$this->isGitAvailable()) {
            $io->error('Git is not available. Please install Git first.');
            $io->note('Download from: https://git-scm.com/downloads');
            return Command::FAILURE;
        }
        if ($method === 'zip' && !class_exists(\ZipArchive::class)) {
            $io->error('PHP zip extension is required for ZIP download.');
            return Command::FAILURE;
        }

        // admin UI 来源：single / aura（非法值回退默认）
        $requestedVariant = $this->stringValue($input->getOption('admin-variant'));
        $variant = $requestedVariant;
        if ($variant === null || !isset($downloadConfig['admin_variants'][$variant])) {
            if ($requestedVariant !== null) {
                $io->warning("Invalid admin variant '{$requestedVariant}', fallback to '{$downloadConfig['admin_variant']}'");
            }
            $variant = $downloadConfig['admin_variant'];
        }

        // 确定要下载的项目
        $projects = [];
        foreach (array_keys($downloadConfig['templates']) as $name) {
            if ($input->getOption($name)) {
                $projects[] = $name;
            }
        }
        if (empty($projects)) {
            $projects = array_keys($downloadConfig['templates']);
        }

        // 按镜像优先级合并仓库表：主镜像优先，缺失项由后备镜像补齐
        $mirrorName = $this->stringValue($input->getOption('mirror')) ?? $downloadConfig['mirror'];
        if (!isset($downloadConfig['mirrors'][$mirrorName])) {
            $io->warning("Unknown mirror '{$mirrorName}', fallback to '{$downloadConfig['mirror']}'");
            $mirrorName = $downloadConfig['mirror'];
        }
        $mirrorConfig = $downloadConfig['mirrors'][$mirrorName];
        $this->gitConfigOptions = !empty($mirrorConfig['no_proxy']) ? '-c http.proxy= -c https.proxy= ' : '';

        $mirrorChain = array_values(array_unique(array_merge(
            [$mirrorName],
            $downloadConfig['fallback_mirrors']
        )));
        $repositories = [];
        foreach (array_reverse($mirrorChain) as $candidateName) {
            $repositories = array_merge(
                $repositories,
                $downloadConfig['mirrors'][$candidateName]['repositories'] ?? []
            );
        }

        $branch = $this->stringValue($input->getOption('branch')) ?: $downloadConfig['default_branch'];
        $rootDir = dirname(base_path());

        $variantLabel = $downloadConfig['admin_variants'][$variant]['label'] ?? $variant;
        $mirrorLabel = $mirrorConfig['label'] ?? $mirrorName;
        $io->text('Method: ' . strtoupper($method));
        $io->text("Source: {$mirrorLabel} ({$mirrorName})");
        if (in_array('admin', $projects, true)) {
            $io->text("Admin UI: {$variantLabel} ({$variant}) -> " . self::ADMIN_DIR);
        }
        $io->text('Templates: ' . implode(', ', $projects));
        $io->text('Branch: ' . $branch);

        // 构建下载目标
        $targets = $this->buildTargets($rootDir, $projects, $repositories, $downloadConfig, $variant, (bool) $input->getOption('skills'), $io);
        if ($targets === []) {
            $io->error('No download target available, check repositories in DownloadCommand');
            return Command::FAILURE;
        }

        // 确保 template 目录存在
        $templateBase = $rootDir . DIRECTORY_SEPARATOR . 'template';
        if (!is_dir($templateBase)) {
            mkdir($templateBase, 0755, true);
            $io->info('Created template directory');
        }

        $force = (bool) $input->getOption('force');
        $success = 0;
        foreach ($targets as $target) {
            $io->section("Downloading: {$target['name']}");
            $targetDir = $target['dir'];

            if (is_dir($targetDir)) {
                // 已存在 → 确认后更新
                if (!$force && !$io->confirm("'{$target['relative']}' already exists. Update it?", true)) {
                    $io->note("Skipped {$target['relative']}");
                    continue;
                }
            } else {
                $parentDir = dirname($targetDir);
                if (!is_dir($parentDir) && !mkdir($parentDir, 0755, true) && !is_dir($parentDir)) {
                    $io->error("Cannot create directory: {$parentDir}");
                    continue;
                }
            }

            if ($method === 'zip') {
                $archiveUrl = $this->archiveUrl($target['url'], $branch, $downloadConfig);
                if ($archiveUrl === null) {
                    $io->error("No archive template configured for repository '{$target['url']}'");
                    continue;
                }
                $io->info("Downloading ZIP: {$archiveUrl}");
                if ($this->downloadAndExtractZip($archiveUrl, $targetDir, $io, $target['repo_key'])) {
                    $io->success("Extracted to {$target['relative']}");
                    $success++;
                }
                continue;
            }

            if (is_dir($targetDir)) {
                if ($this->execGit("cd \"{$targetDir}\" && git pull origin {$branch}", $io)) {
                    $io->success("Updated {$target['relative']}");
                    $success++;
                }
                continue;
            }

            $io->info("Cloning {$target['name']}...");
            if ($this->execGit("cd \"{$rootDir}\" && git clone -b {$branch} {$target['url']} {$target['relative']}", $io)) {
                $io->success("Cloned to {$target['relative']}");
                $success++;
            }
        }

        // 总结
        $total = count($targets);
        $io->section('Summary');
        if ($success === $total) {
            $io->success("All {$total} template(s) downloaded successfully!");
            return Command::SUCCESS;
        }
        if ($success > 0) {
            $io->warning("Downloaded {$success} / {$total} template(s)");
            return Command::FAILURE;
        }
        $io->error('Failed to download any template');
        return Command::FAILURE;
    }

    /**
     * 构建下载目标：admin（single/aura 统一落到 template/admin）/ web / skills
     *
     * @return array<int, array{name:string,url:string,dir:string,relative:string,repo_key:string}>
     */
    private function buildTargets(
        string $rootDir,
        array $projects,
        array $repositories,
        array $downloadConfig,
        string $variant,
        bool $withSkills,
        SymfonyStyle $io
    ): array {
        $targets = [];

        foreach ($projects as $template) {
            $variantConfig = [];
            // admin 来源由 admin_variants 决定，统一重命名落到 template/admin
            if ($template === 'admin') {
                $variantConfig = $downloadConfig['admin_variants'][$variant] ?? [];
                $repoKey = $variantConfig['repo'] ?? $downloadConfig['admin_variant'];
            } else {
                $repoKey = $template;
            }

            $url = $repositories[$repoKey] ?? null;
            if (!is_string($url) || $url === '') {
                $io->warning("No repository configured for template '{$template}', skipped");
                continue;
            }

            $relative = str_replace('/', DIRECTORY_SEPARATOR, $downloadConfig['templates'][$template]);
            $targets[$relative] = [
                'name' => $variantConfig['label'] ?? $template,
                'url' => $url,
                'dir' => $rootDir . DIRECTORY_SEPARATOR . $relative,
                'relative' => $relative,
                'repo_key' => $repoKey,
            ];
        }

        if ($withSkills) {
            $skillsUrl = $repositories['skills'] ?? null;
            if (is_string($skillsUrl) && $skillsUrl !== '') {
                $skillsRelative = str_replace('/', DIRECTORY_SEPARATOR, $downloadConfig['extra']['skills']);
                $targets[$skillsRelative] = [
                    'name' => 'Skills',
                    'url' => $skillsUrl,
                    'dir' => $rootDir . DIRECTORY_SEPARATOR . $skillsRelative,
                    'relative' => $skillsRelative,
                    'repo_key' => 'skills',
                ];
            } else {
                $io->warning('No repository configured for skills, skipped');
            }
        }

        return array_values($targets);
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * 根据镜像源生成仓库归档 ZIP 地址
     */
    private function archiveUrl(string $repoUrl, string $branch, array $downloadConfig): ?string
    {
        $templates = $downloadConfig['archive_templates'];
        $host = parse_url($repoUrl, PHP_URL_HOST);
        $template = is_string($host) ? ($templates[$host] ?? null) : null;
        if (!is_string($template) || $template === '') {
            return null;
        }

        $path = preg_replace('#^https?://[^/]+/+#', '', $repoUrl) ?? '';
        $path = preg_replace('#\.git$#', '', $path) ?? '';
        if ($path === '') {
            return null;
        }

        return str_replace(['{path}', '{branch}'], [$path, $branch], $template);
    }

    /**
     * 下载 ZIP 归档并解压到目标目录（自动去掉归档顶层目录，实现统一重命名落地）
     */
    private function downloadAndExtractZip(string $url, string $targetDir, SymfonyStyle $io, string $repoKey): bool
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'madong_zip_');
        if ($tmpFile === false) {
            $io->error('Cannot create temporary ZIP file');
            return false;
        }

        // 优先用 curl.exe：PHP 流包装器在部分 Windows 环境下无法访问 HTTPS
        $curlOutput = [];
        $curlCode = 1;
        $curlCommand = 'curl.exe -L --fail --silent --show-error --max-time 300 -o '
            . escapeshellarg($tmpFile) . ' ' . escapeshellarg($url) . ' 2>&1';
        exec($curlCommand, $curlOutput, $curlCode);
        if ($curlCode !== 0 || !is_file($tmpFile) || filesize($tmpFile) === 0) {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 300,
                    'header' => "User-Agent: madong-download\r\n",
                ],
            ]);
            $data = @file_get_contents($url, false, $context);
            if ($data === false || file_put_contents($tmpFile, $data) === false) {
                @unlink($tmpFile);
                $io->error("Failed to download ZIP: {$url}");
                if ($curlOutput !== []) {
                    $io->error(implode("\n", $curlOutput));
                }
                return false;
            }
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpFile) !== true) {
            @unlink($tmpFile);
            $io->error("Failed to open ZIP: {$url}");
            return false;
        }

        // 检测归档顶层目录（如 madong-single-main/），解压时剥掉以统一重命名到目标目录
        $prefix = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name)) {
                continue;
            }
            $pos = strpos($name, '/');
            if ($pos !== false && $pos > 0) {
                $prefix = substr($name, 0, $pos + 1);
                break;
            }
        }

        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            $zip->close();
            @unlink($tmpFile);
            $io->error("Cannot create directory: {$targetDir}");
            return false;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || $name === '') {
                continue;
            }
            if ($prefix !== null && str_starts_with($name, $prefix)) {
                $name = substr($name, strlen($prefix));
            }
            if ($name === '' || preg_match('#(^|/)\.\.(/|$)#', $name) === 1) {
                continue;
            }

            $dest = $targetDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);
            if (str_ends_with($name, '/')) {
                if (!is_dir($dest)) {
                    mkdir($dest, 0755, true);
                }
                continue;
            }

            $parent = dirname($dest);
            if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
                continue;
            }
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                continue;
            }
            file_put_contents($dest, $content);
        }

        $zip->close();
        @unlink($tmpFile);
        $this->patchDownloadedTemplate($targetDir, $repoKey);
        return true;
    }

    /**
     * 修正上游模板归档中的已知构建问题（标准版仅涉及 single/aura/web）
     */
    private function patchDownloadedTemplate(string $targetDir, string $repoKey): void
    {
        if (!in_array($repoKey, ['single', 'aura', 'web'], true)) {
            return;
        }

        if ($repoKey === 'web') {
            $this->patchWebTemplate($targetDir);
            return;
        }

        foreach (['tooling/node-utils/package.json', 'internal/node-utils/package.json'] as $relativePath) {
            $nodeUtilsPackage = $targetDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (!is_file($nodeUtilsPackage)) {
                continue;
            }
            $content = file_get_contents($nodeUtilsPackage);
            if (is_string($content) && str_contains($content, 'node ./scripts/build.mjs')) {
                file_put_contents($nodeUtilsPackage, str_replace(
                    'node ./scripts/build.mjs',
                    'pnpm exec tsdown',
                    $content
                ));
            }
        }

        $rootPackage = $targetDir . DIRECTORY_SEPARATOR . 'package.json';
        if (is_file($rootPackage)) {
            $content = file_get_contents($rootPackage);
            if (is_string($content) && str_contains($content, 'is-ci || pnpm exec lefthook install')) {
                file_put_contents($rootPackage, str_replace(
                    'is-ci || pnpm exec lefthook install',
                    'is-ci || pnpm exec lefthook install || exit 0',
                    $content
                ));
            }
        }
    }

    /**
     * web 模板改为静态生成，并补出 index.html 供同域部署
     */
    private function patchWebTemplate(string $targetDir): void
    {
        $rootPackage = $targetDir . DIRECTORY_SEPARATOR . 'package.json';
        if (is_file($rootPackage)) {
            $content = file_get_contents($rootPackage);
            if (is_string($content) && str_contains($content, 'nuxi build && node scripts/materialize-output.mjs')) {
                file_put_contents($rootPackage, str_replace(
                    'nuxi build && node scripts/materialize-output.mjs',
                    'nuxi generate && node scripts/ensure-index.mjs',
                    $content
                ));
            }
        }

        $scriptDir = $targetDir . DIRECTORY_SEPARATOR . 'scripts';
        if (!is_dir($scriptDir) && !mkdir($scriptDir, 0755, true) && !is_dir($scriptDir)) {
            return;
        }
        file_put_contents($scriptDir . DIRECTORY_SEPARATOR . 'ensure-index.mjs', <<<'JS'
import { access, copyFile } from 'node:fs/promises'
import { join } from 'node:path'

const publicDir = join(process.cwd(), '.output', 'public')
const indexFile = join(publicDir, 'index.html')
const fallbackFile = join(publicDir, '200.html')

try {
  await access(indexFile)
} catch {
  await copyFile(fallbackFile, indexFile)
  console.log('[ensure-index] created index.html from 200.html')
}
JS);
    }

    /**
     * 执行 Git 命令并输出结果
     */
    private function execGit(string $command, SymfonyStyle $io): bool
    {
        $command = $this->gitConfigOptions . $command;
        $io->text("> {$command}");
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);
        foreach ($output as $line) {
            $io->text($line);
        }
        return $returnVar === 0;
    }

    private function isGitAvailable(): bool
    {
        $output = [];
        $returnVar = 0;
        exec('git --version', $output, $returnVar);
        return $returnVar === 0;
    }
}
