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

namespace app\command\message;

use app\command\BaseCommand;
use core\business\message\MessageDataSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * 消息模板数据同步
 *
 * 将消息模板数据文件与数据库对齐：
 *   - 框架：resource/data/message/category.php（source=system）
 *   - 插件：plugin/{name}/resource/data/message/category.php（source=plugin:{name}）
 *   （插件目录取自 config/info.php 的 resource.message，缺省 data/message）
 *
 * 三种模式：
 *   - 增量（默认）：仅补插文件中存在但数据库中缺失的数据，已存在则跳过（安全、幂等）
 *   - --sync：智能同步 —— 新增缺失 + 更新已有 + 删除本来源中文件中已不存在的数据
 *   - --full：清空本来源数据后全量重导（仅影响该来源，不影响框架/其他插件/后台自建数据）
 *
 * 只处理同来源（source）的数据，卸载清理与重装升级均以该来源为边界。
 *
 * 使用方法：
 *   php webman madong:migrate-message                          # 仅同步框架消息数据（增量）
 *   php webman madong:migrate-message workflow                 # 同步指定插件
 *   php webman madong:migrate-message workflow --sync          # 智能同步
 *   php webman madong:migrate-message --all --full             # 框架 + 全部插件清空重导
 *   php webman madong:migrate-message workflow --sync --no-interaction
 *
 * @author Mr.April
 * @since 1.0.0
 */
#[AsCommand(
    name: 'madong:migrate-message',
    description: '同步消息分类/定义/模板到消息中心表（增量/--sync 智能同步/--full 清空重导）',
    hidden: false
)]
class MigrateMessageCommand extends BaseCommand
{
    /**
     * 框架消息数据来源标识
     */
    private const FRAMEWORK_SOURCE = 'system';

    protected function configure(): void
    {
        $this->addArgument(
            'plugin',
            InputArgument::OPTIONAL,
            '插件名称（如 workflow）；省略时仅同步框架消息数据'
        );
        $this->addOption(
            'all',
            'a',
            InputOption::VALUE_NONE,
            '同步框架 + 所有插件的消息数据'
        );
        $this->addOption(
            'sync',
            's',
            InputOption::VALUE_NONE,
            '智能同步：新增缺失 + 更新已有 + 删除本来源中文件中不存在的数据'
        );
        $this->addOption(
            'full',
            'o',
            InputOption::VALUE_NONE,
            '清空重导：清空本来源消息数据后重新导入（仅影响本来源）'
        );
    }

    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $sync   = $input->getOption('sync');
        $full   = $input->getOption('full');
        $all    = $input->getOption('all');
        $plugin = $input->getArgument('plugin');

        $io->title('Message Data Migration');

        if ($sync && $full) {
            $io->error('--sync 和 --full 互斥，请只选择其中一种模式。');
            return Command::FAILURE;
        }

        $mode = match (true) {
            $full => MessageDataSync::MODE_FULL,
            $sync => MessageDataSync::MODE_SYNC,
            default => MessageDataSync::MODE_INCREMENT,
        };

        if ($mode !== MessageDataSync::MODE_INCREMENT) {
            $this->confirmDestructive($io, $input, $mode);
        }

        $targets = $this->buildTargets($all, $plugin);
        if (empty($targets)) {
            $io->warning('没有找到可同步的消息数据文件。');
            return Command::SUCCESS;
        }

        $overallResult = Command::SUCCESS;

        foreach ($targets as $target) {
            $io->section(sprintf('%s (source=%s)', $target['name'], $target['source']));

            if (!is_file($target['file'])) {
                $io->warning("消息数据文件不存在: {$target['file']}，跳过。");
                continue;
            }

            $categories = require $target['file'];
            if (!is_array($categories)) {
                $io->error("消息数据文件格式错误（应返回数组）: {$target['file']}");
                $overallResult = Command::FAILURE;
                continue;
            }

            $stat = (new MessageDataSync($target['source']))->sync($categories, $mode);

            $io->success(sprintf(
                '完成：新增 %d，更新 %d，跳过 %d，删除 %d',
                $stat['created'],
                $stat['updated'],
                $stat['skipped'],
                $stat['deleted']
            ));
        }

        return $overallResult;
    }

    /**
     * 危险操作确认
     */
    private function confirmDestructive(SymfonyStyle $io, InputInterface $input, string $mode): void
    {
        if (!$input->isInteractive()) {
            return;
        }

        if ($mode === MessageDataSync::MODE_SYNC) {
            $io->caution('⚠ --sync 模式将：新增缺失 + 更新已有 + 删除本来源中文件中已不存在的数据！');
            if (!$io->confirm('确认执行智能同步？', false)) {
                $io->warning('已取消。');
                exit(Command::SUCCESS);
            }
            return;
        }

        $io->caution('⚠ --full 模式将清空本来源（source）的分类/定义/模板后重新导入！');
        $io->warning('   后台自建数据（source=user）、框架及其他插件的数据不受影响。');
        if (!$io->confirm('确认清空本来源消息数据并重新全量导入？', false)) {
            $io->warning('已取消。');
            exit(Command::SUCCESS);
        }
    }

    /**
     * 构建同步目标列表
     *
     * @return array<int, array{name: string, source: string, file: string}>
     */
    private function buildTargets(bool $all, ?string $plugin): array
    {
        $targets = [];

        // --all 或未指定插件时，均包含框架消息数据
        if ($all || empty($plugin)) {
            $targets[] = [
                'name'   => 'framework',
                'source' => self::FRAMEWORK_SOURCE,
                'file'   => base_path('resource/data/message/category.php'),
            ];
        }

        if ($all) {
            foreach ($this->discoverPlugins() as $name) {
                $targets[] = $this->buildPluginTarget($name);
            }
        } elseif (!empty($plugin)) {
            $targets[] = $this->buildPluginTarget($plugin);
        }

        return $targets;
    }

    /**
     * 构建插件同步目标
     */
    private function buildPluginTarget(string $pluginName): array
    {
        $pluginPath = base_path("plugin/{$pluginName}");

        return [
            'name'   => $pluginName,
            'source' => "plugin:{$pluginName}",
            'file'   => $pluginPath . '/resource/' . $this->resolveMessageDir($pluginPath) . '/category.php',
        ];
    }

    /**
     * 解析插件消息目录
     * 优先读取 config/info.php 的 resource.message，缺省 resource/data/message
     */
    private function resolveMessageDir(string $pluginPath): string
    {
        $infoFile = $pluginPath . '/config/info.php';
        $info     = is_file($infoFile) ? include $infoFile : [];
        $dir      = is_array($info) ? ($info['resource']['message'] ?? null) : null;

        return $dir ?: 'data/message';
    }

    /**
     * 发现所有插件
     */
    private function discoverPlugins(): array
    {
        $pluginDir = base_path('plugin');
        if (!is_dir($pluginDir)) {
            return [];
        }

        $plugins  = [];
        $iterator = new \DirectoryIterator($pluginDir);

        foreach ($iterator as $item) {
            if ($item->isDot() || !$item->isDir()) {
                continue;
            }
            $name = $item->getFilename();
            if (file_exists($pluginDir . '/' . $name . '/config/info.php')) {
                $plugins[] = $name;
            }
        }

        sort($plugins);
        return $plugins;
    }
}