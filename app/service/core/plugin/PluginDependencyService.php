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

namespace app\service\core\plugin;

use core\business\plugin\traits\ProgressStreamTrait;
use core\foundation\tool\Sse;
use core\io\uuid\UUIDGenerator;

/**
 * 插件依赖服务
 *
 * 提供依赖状态查询与「补装/重试」能力：
 * - status(): 读取插件 config/installed.php 中持久化的依赖状态（不依赖数据库）
 * - install(): 重新执行依赖安装流水线，供 CLI 与在线（SSE）两种模式共用
 */
class PluginDependencyService extends PluginBaseService
{
    use ProgressStreamTrait;

    /**
     * 查询插件依赖状态
     *
     * @param string $code 插件编码
     *
     * @return array{
     *     plugin: string,
     *     installed: bool,
     *     status: string,
     *     updated_at: string|null,
     *     endpoints: array<string, array<string, array{status: string, version: string, error: string}>>
     * }
     * @throws \Exception
     */
    public function status(string $code): array
    {
        $pluginDir     = $this->plugin_path . DIRECTORY_SEPARATOR . $code;
        $infoFile      = $pluginDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'info.php';
        $installedFile = $pluginDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'installed.php';

        if (!is_dir($pluginDir) || !is_file($infoFile)) {
            throw new \Exception('插件不存在：' . $code);
        }

        $info      = include $infoFile;
        $info      = is_array($info) ? $info : [];
        $installed = is_file($installedFile) ? include $installedFile : [];
        $installed = is_array($installed) ? $installed : [];

        $expected = $this->expectedDependencies($info);
        $state    = $installed['dependencies'] ?? [];
        $state    = is_array($state) ? $state : [];

        $endpoints = [];
        $overall   = [];

        foreach ($expected as $type => $packages) {
            $endpoints[$type] = [];
            foreach ($packages as $package => $version) {
                $item      = $state[$type][$package] ?? null;
                $itemState = is_array($item) ? $item : [];
                $status    = (string)($itemState['status'] ?? 'pending');

                $endpoints[$type][$package] = [
                    'status'  => $status,
                    'version' => (string)($itemState['version'] ?? $version),
                    'error'   => (string)($itemState['error'] ?? ''),
                ];

                $overall[] = $status;
            }
        }

        // 声明中不存在但状态文件里残留的项也一并展示
        foreach ($state as $type => $packages) {
            foreach ((array)$packages as $package => $item) {
                if (isset($endpoints[$type][$package])) {
                    continue;
                }
                $item      = is_array($item) ? $item : [];
                $status    = (string)($item['status'] ?? 'pending');
                $endpoints[$type][$package] = [
                    'status'  => $status,
                    'version' => (string)($item['version'] ?? '*'),
                    'error'   => (string)($item['error'] ?? ''),
                ];
                $overall[] = $status;
            }
        }

        if (empty($overall)) {
            $status = 'none';
        } elseif (in_array('failed', $overall, true)) {
            $status = 'failed';
        } elseif (in_array('pending', $overall, true)) {
            $status = 'pending';
        } else {
            $status = 'installed';
        }

        return [
            'plugin'     => $code,
            'installed'  => is_file($installedFile),
            'status'     => $status,
            'updated_at' => $installed['dependency_updated_at'] ?? null,
            'endpoints'  => $endpoints,
        ];
    }

    /**
     * 补装插件依赖（同步执行，CLI 使用）
     *
     * 在线（SSE）请使用 installOnline()：真实执行 composer/pnpm 可能超过
     * 10 分钟，同步执行会阻塞 worker 导致 SSE 失去通信。
     *
     * @return \Generator SSE 事件流
     */
    public function install(string $code): \Generator
    {
        $sessionUuid = UUIDGenerator::generate();
        $request     = request();
        if ($request) {
            $sessionUuid = $request->input('uuid', $sessionUuid);
        }

        $pluginDir = $this->plugin_path . DIRECTORY_SEPARATOR . $code;
        if (!is_dir($pluginDir)) {
            yield Sse::error('插件不存在：' . $code, [], $sessionUuid);
            return;
        }

        $className = "plugin\\{$code}\\Install";
        if (!class_exists($className)) {
            yield Sse::error("插件未提供 Install.php，无法补装依赖", [], $sessionUuid);
            return;
        }

        yield Sse::progress('开始补装插件依赖', 5, ['plugin' => $code], $sessionUuid);

        $outputFile = $this->createProgressFile('deps', $sessionUuid);

        try {
            $this->captureOutputWithProgress(function () use ($code, $outputFile) {
                $this->runDependencyInstall($code, $outputFile);
            }, $outputFile);
        } catch (\Throwable $e) {
            $this->cleanupProgressFile($outputFile);
            yield Sse::error('依赖补装失败：' . $e->getMessage(), [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ], $sessionUuid);
            return;
        }

        yield from $this->yieldProgressLines($outputFile, 80, [], $sessionUuid);
        $this->cleanupProgressFile($outputFile);

        $status = $this->status($code);

        yield Sse::completed('依赖补装流程结束（状态：' . $status['status'] . '）', [
            'plugin'         => $code,
            'dependency_status' => $status['status'],
            'dependencies'   => $status['endpoints'],
        ], $sessionUuid);
    }

    /**
     * 补装插件依赖（在线模式，SSE 流式）
     *
     * 真实执行 composer/pnpm 可能长时间无输出，同步执行会阻塞 worker 并使
     * SSE 连接静默断开；改为后台子进程执行 + 轮询进度文件实时推送 +
     * 静默期心跳保活。
     *
     * @return \Generator SSE 事件流
     */
    public function installOnline(string $code): \Generator
    {
        $sessionUuid = UUIDGenerator::generate();
        $request     = request();
        if ($request) {
            $sessionUuid = (string)$request->input('uuid', $sessionUuid);
        }

        $pluginDir = $this->plugin_path . DIRECTORY_SEPARATOR . $code;
        if (!is_dir($pluginDir)) {
            yield Sse::error('插件不存在：' . $code, [], $sessionUuid);
            return;
        }

        $className = "plugin\\{$code}\\Install";
        if (!class_exists($className)) {
            yield Sse::error("插件未提供 Install.php，无法补装依赖", [], $sessionUuid);
            return;
        }

        yield Sse::progress('开始补装插件依赖', 5, ['plugin' => $code], $sessionUuid);

        // 创建进度文件，子进程输出/回调均写入该文件
        $outputFile = $this->createProgressFile('deps', $sessionUuid);

        $command = sprintf(
            '%s %s madong-plugin:install-deps %s --progress %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('webman')),
            escapeshellarg($code),
            escapeshellarg($outputFile)
        );

        $process = $this->spawnDetachedProcess($command, $outputFile);
        if (!is_resource($process)) {
            $this->cleanupProgressFile($outputFile);
            yield Sse::error('依赖补装进程启动失败', [], $sessionUuid);
            return;
        }

        // 轮询进度文件，实时推送安装日志（静默期发心跳，防止中间层断开 SSE）
        $offset       = 0;
        $step         = 0;
        $exitCode     = -1;
        $lastOutputAt = microtime(true);
        try {
            while (true) {
                [$lines, $offset] = $this->readNewProgressLines($outputFile, $offset);
                if ($lines !== []) {
                    foreach ($lines as $line) {
                        $step++;
                        yield Sse::progress($line, min(80, 10 + $step * 2), [], $sessionUuid);
                    }
                    $lastOutputAt = microtime(true);
                } elseif (microtime(true) - $lastOutputAt >= 3) {
                    yield Sse::heartbeat(null);
                    $lastOutputAt = microtime(true);
                }

                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = (int)$status['exitcode'];
                    break;
                }
                usleep(800000);
            }
        } finally {
            proc_close($process);
        }

        // 收尾：读取子进程退出前后写入的剩余日志
        [$lines] = $this->readNewProgressLines($outputFile, $offset, true);
        foreach ($lines as $line) {
            yield Sse::progress($line, 85, [], $sessionUuid);
        }

        $this->cleanupProgressFile($outputFile);

        if ($exitCode !== 0) {
            yield Sse::error("依赖补装执行失败（exit: {$exitCode}）", [], $sessionUuid);
            return;
        }

        $status = $this->status($code);

        yield Sse::completed('依赖补装流程结束（状态：' . $status['status'] . '）', [
            'plugin'         => $code,
            'dependency_status' => $status['status'],
            'dependencies'   => $status['endpoints'],
        ], $sessionUuid);
    }

    /**
     * 真实执行依赖补装（供同步/子进程共用）
     *
     * @param string $code 插件编码
     * @param string|null $progressFile 进度文件（非 null 时输出实时写入该文件）
     *
     * @return array{status: string, dependencies: array}
     * @throws \Throwable
     */
    public function runDependencyInstall(string $code, ?string $progressFile = null): array
    {
        $className = "plugin\\{$code}\\Install";
        if (!class_exists($className)) {
            throw new \RuntimeException("插件未提供 Install.php，无法补装依赖");
        }

        /** @var \core\business\plugin\PluginInstall $instance */
        $instance = new $className();

        if ($progressFile !== null) {
            $instance->setOnlineMode(true);
            $instance->setProgressCallback(static function (string $message, ?int $progress = null) use ($progressFile): void {
                @file_put_contents($progressFile, $message . "\n", FILE_APPEND);
            });
        } else {
            $instance->setOnlineMode(false);
        }

        $instance->setContext('default');

        return $instance->installDependencies(true);
    }

    /**
     * 计算插件声明的依赖清单
     */
    private function expectedDependencies(array $info): array
    {
        $npm = $info['npm_require'] ?? [];

        return [
            'composer' => $info['require']['composer'] ?? [],
            'admin'    => array_merge($npm['admin'] ?? [], $npm['common'] ?? []),
            'web'      => array_merge($npm['web'] ?? [], $npm['common'] ?? []),
        ];
    }
}
