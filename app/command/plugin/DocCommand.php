<?php
declare(strict_types=1);

/**
 *+------------------
 * madong
 *+------------------
 * 插件文档生成命令
 *
 * 将插件 resource/docs/ 下按文件名排序的 Markdown（00-序言 / 01-概述 ...）
 * 合并转换为一份 DOCX 富文本文档，输出到 runtime/docs/{name}.docx。
 *
 * 用法：
 *   php webman madong-plugin:doc logviewer
 *   php webman madong-plugin:doc codegen --out=backend/runtime/docs
 *   php webman madong-plugin:doc --all
 *
 * 该命令复用项目已有的 erusev/parsedown 依赖，纯 PHP 手写 OOXML，
 * 不引入任何新的 composer 依赖。转换核心见 MarkdownDocxConverter。
 *
 * @author Mr.April
 * @since 1.0.0
 */

namespace app\command\plugin;

use app\command\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'madong-plugin:doc',
    description: 'Generate docx documentation for plugin(s) from resource/docs markdown',
    aliases: ['madong-plugin:doc'],
    hidden: false
)]
class DocCommand extends BaseCommand
{
    /** backend 根目录（__DIR__ = backend/app/command/plugin） */
    private string $backendDir;

    /** 插件目录 backend/plugin */
    private string $pluginDir;

    protected function configure(): void
    {
        $this->addArgument('plugin', InputArgument::OPTIONAL, 'Plugin name (directory under backend/plugin)');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Generate docs for all plugins that have resource/docs');
        $this->addOption('out', null, InputOption::VALUE_REQUIRED, 'Output directory', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->backendDir = dirname(__DIR__, 3);
        $this->pluginDir = $this->backendDir . '/plugin';
        $outDir = $input->getOption('out') ?: $this->backendDir . '/runtime/docs';

        $all = (bool) $input->getOption('all');
        $plugin = $input->getArgument('plugin');

        if (!$all && !$plugin) {
            $io->error('请提供插件名（如 logviewer）或使用 --all 批量生成');
            $io->note('示例：php webman madong-plugin:doc codegen');
            return self::FAILURE;
        }

        $targets = [];
        if ($all) {
            foreach (glob($this->pluginDir . '/*', GLOB_ONLYDIR) as $dir) {
                if (is_dir($dir . '/resource/docs')) {
                    $targets[] = $dir;
                }
            }
            if ($targets === []) {
                $io->warning('未找到任何包含 resource/docs 的插件');
                return self::SUCCESS;
            }
        } else {
            $dir = $this->pluginDir . '/' . $plugin;
            if (!is_dir($dir)) {
                return $this->outputError($io, "插件目录不存在：{$dir}");
            }
            $targets[] = $dir;
        }

        if (!is_dir($outDir)) {
            mkdir($outDir, 0777, true);
        }

        $converter = new MarkdownDocxConverter();
        $count = 0;

        foreach ($targets as $dir) {
            $docsDir = $dir . '/resource/docs';
            if (!is_dir($docsDir)) {
                $io->warning('跳过 ' . basename($dir) . '：无 resource/docs');
                continue;
            }

            $files = glob($docsDir . '/*.md');
            if ($files === []) {
                $io->warning('跳过 ' . basename($dir) . '：resource/docs 下无 .md');
                continue;
            }
            // 按文件名自然排序（即 00-序言 < 01-概述 ...）
            natsort($files);

            $info = $this->readInfo($dir . '/config/info.php');
            $name = $info['name'] ?? basename($dir);
            $version = $info['version'] ?? '1.0.0';
            $author = $info['author'] ?? '';
            $description = $info['description'] ?? '';

            $markdown = [];
            foreach ($files as $f) {
                $markdown[] = file_get_contents($f);
            }

            $meta = [
                'title' => $name . ' 插件文档',
                'subtitle' => $description !== '' ? mb_substr($description, 0, 60) : '使用与开发文档',
                'meta' => '版本 ' . $version . ($author !== '' ? '  ·  作者 ' . $author : ''),
            ];

            try {
                $data = $converter->convert($markdown, $meta);
            } catch (\Throwable $e) {
                return $this->outputError($io, '生成文档失败：' . $e->getMessage(), $e);
            }

            $outFile = rtrim($outDir, '/') . '/' . $name . '.docx';
            file_put_contents($outFile, $data);
            $count++;
            $io->success(sprintf(
                '已生成 %s.docx（%d 字节，%d 个文档）-> %s',
                $name,
                strlen($data),
                count($files),
                $outFile
            ));
        }

        $io->note("共生成 {$count} 份文档，输出目录：{$outDir}");
        return self::SUCCESS;
    }

    /**
     * 读取插件 config/info.php 返回数组
     */
    private function readInfo(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $cfg = @include $path;
        return is_array($cfg) ? $cfg : [];
    }
}
