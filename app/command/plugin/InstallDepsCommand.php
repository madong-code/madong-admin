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
use app\service\core\plugin\PluginDependencyService;
use support\Container;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * 插件依赖补装命令
 * 使用方法：
 * - 查看依赖状态：php webman madong-plugin:install-deps plugin-name --status
 * - 补装依赖：    php webman madong-plugin:install-deps plugin-name
 *
 * 说明：真实执行 composer/pnpm 安装命令，不受 dependencies.auto_install 配置限制，
 * 用于插件安装时网络失败（如 GitHub 不通）后的手动/自动补装。
 *
 * @author Mr.April
 * @since 1.0.0
 */
#[AsCommand(
    name: 'madong-plugin:install-deps',
    description: 'Install or retry plugin dependencies (composer/npm)',
    aliases: ['madong-plugin:install-deps'],
    hidden: false
)]
class InstallDepsCommand extends BaseCommand
{
    /**
     * 配置命令
     */
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Plugin name');
        $this->addOption('status', null, InputOption::VALUE_NONE, 'Only show dependency status');
    }

    /**
     * 执行命令
     */
    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');

        $io->title('Plugin Dependency Installer');

        try {
            /** @var PluginDependencyService $service */
            $service = Container::get(PluginDependencyService::class);

            if ($input->getOption('status')) {
                $status = $service->status($name);
                $io->writeln(sprintf('Plugin: %s / Status: %s', $status['plugin'], $status['status']));
                $io->writeln(sprintf('Installed: %s', $status['installed'] ? 'yes' : 'no'));

                foreach ($status['endpoints'] as $type => $packages) {
                    if (empty($packages)) {
                        continue;
                    }
                    $io->section($type);
                    $rows = [];
                    foreach ($packages as $package => $item) {
                        $rows[] = [$package, $item['version'], $item['status'], $item['error']];
                    }
                    $io->table(['Package', 'Version', 'Status', 'Error'], $rows);
                }

                return Command::SUCCESS;
            }

            $io->info("Installing dependencies for plugin: {$name}");

            return $this->executeStream($service->install($name), $io, $name);

        } catch (\Throwable $e) {
            return $this->outputError($io, sprintf("Dependency install failed: %s", $e->getMessage()), $e);
        }
    }
}
