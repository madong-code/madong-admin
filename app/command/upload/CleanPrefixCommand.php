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

namespace app\command\upload;

use app\command\BaseCommand;
use app\service\core\upload\CloudResourceCleanerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * 按目录前缀清理上传残留资源
 *
 * 回收 {dirname}/{code}/ 前缀下的云对象、本地目录与附件记录（dirname 默认 upload）。
 *
 * ┌─ 使用示例 ─────────────────────────────────────────────────────┐
 * │ php webman upload:clean-prefix portal                          │ 预演（默认，不删除）
 * │ php webman upload:clean-prefix portal --apply                  │ 真正执行清理
 * │ php webman upload:clean-prefix portal --apply --keep-db        │ 只清文件与云对象，保留附件记录
 * │ php webman upload:clean-prefix portal --apply --yes --limit=50000 │ 对象较多时显式确认
 * └───────────────────────────────────────────────────────────────┘
 *
 * @author Mr.April
 * @since 1.0.0
 */
#[AsCommand(
    name: 'upload:clean-prefix',
    description: 'Clean plugin upload resources by directory prefix (cloud objects + local dir + upload records)',
    aliases: ['upload:clean-prefix'],
    hidden: false
)]
class CleanPrefixCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('code', InputArgument::REQUIRED, '插件编码（同时作为存储子目录名，如 portal）')
            ->addOption('apply', null, InputOption::VALUE_NONE, '真正执行删除；缺省为预演（只盘点，不删任何资源）')
            ->addOption('yes', null, InputOption::VALUE_NONE, '确认清理超出数量上限的对象（配合 --limit 使用）')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, '单次清理对象数量上限', (string)CloudResourceCleanerService::DEFAULT_LIMIT)
            ->addOption('keep-db', null, InputOption::VALUE_NONE, '保留 md_sys_upload 附件记录，只清云对象与本地目录');
    }

    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $code  = (string)$input->getArgument('code');
        $apply = (bool)$input->getOption('apply');

        $io->title($apply ? '上传资源清理（实际执行）' : '上传资源清理（预演）');

        $options = [
            'limit'   => (int)$input->getOption('limit'),
            'yes'     => (bool)$input->getOption('yes'),
            'keep_db' => (bool)$input->getOption('keep-db'),
        ];

        $service = new CloudResourceCleanerService();
        try {
            $report = $apply ? $service->clean($code, $options) : $service->preview($code, $options);
        } catch (\Throwable $e) {
            return $this->outputError($io, $e->getMessage(), $e);
        }

        $io->text(sprintf('清理前缀: <info>%s</info>   存储方式: <info>%s</info>', $report['prefix'], $report['mode']));

        $cloud = $report['cloud'];
        $io->table(['目标', '指标', '值'], [
            ['云端对象', '状态', $cloud['enabled'] ? '已启用' : '已跳过（七牛配置不完整）'],
            ['云端对象', '命中数量', (string)$cloud['total']],
            ['云端对象', '命中大小', formatBytes((int)$cloud['bytes'])],
            ['云端对象', '已删除', (string)$cloud['deleted']],
            ['云端对象', '失败', (string)$cloud['failed']],
            ['本地目录', '路径', (string)$report['local']['path']],
            ['本地目录', '存在', !empty($report['local']['exists']) ? '是' : '否'],
            ['本地目录', '文件数 / 大小', $report['local']['files'] . ' / ' . formatBytes((int)$report['local']['bytes'])],
            ['本地目录', '已删除', !empty($report['local']['deleted']) ? '是' : '否'],
            ['附件记录', '命中行数', (string)$report['database']['matched']],
            ['附件记录', '已删除', (string)$report['database']['deleted']],
            ['附件记录', '保留记录', !empty($report['database']['skipped']) ? '是（--keep-db）' : '否'],
        ]);

        if (!empty($cloud['sample'])) {
            $io->text('云端对象样例（最多 20 条）：');
            foreach ($cloud['sample'] as $key) {
                $io->text('  ' . $key);
            }
        }
        foreach (array_slice($cloud['errors'], 0, 10) as $error) {
            $io->warning($error);
        }

        if ($apply) {
            $io->success('清理完成。');
        } else {
            $io->note('以上为预演结果，未删除任何资源。确认无误后加 --apply 再执行一次。');
        }

        return self::SUCCESS;
    }
}