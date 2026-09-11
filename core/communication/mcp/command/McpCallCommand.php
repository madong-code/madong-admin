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

namespace core\communication\mcp\command;

use core\communication\mcp\discovery\ToolManifest;
use core\communication\mcp\security\McpUser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * madong-mcp:call：CLI 直接调用 MCP 工具（绕过 SDK 传输层；CLI 身份视为超级管理员）
 */
class McpCallCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Tool name (see madong-mcp:list)')
            ->addArgument('json', InputArgument::OPTIONAL, 'Arguments as JSON object, keys must match inputSchema property names', '{}');
    }

    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = (string) $input->getArgument('name');

        try {
            $entries = (new ToolManifest())->load();
        } catch (\Throwable $e) {
            $io->error('Manifest load failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $entry = null;
        foreach ($entries as $item) {
            if ($item['name'] === $name) {
                $entry = $item;
                break;
            }
        }
        if ($entry === null) {
            $io->error(sprintf('Tool "%s" not found. Available: %s', $name, implode(', ', array_column($entries, 'name'))));
            return Command::FAILURE;
        }

        $arguments = json_decode((string) $input->getArgument('json'), true);
        if (!is_array($arguments)) {
            $io->error('Arguments must be a valid JSON object');
            return Command::FAILURE;
        }

        $class = (string) $entry['class'];
        $method = (string) $entry['method'];
        $instance = new $class(new McpUser('cli', ['*'], [], 'cli'));

        try {
            $result = $instance->{$method}(...$arguments);
        } catch (\Throwable $e) {
            $io->error(sprintf('%s: %s', $e::class, $e->getMessage()));
            return Command::FAILURE;
        }

        $io->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}
