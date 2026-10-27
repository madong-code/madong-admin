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
namespace core\business\plugin;

/**
 * 依赖命令执行器
 *
 * 统一封装 proc_open 调用，提供：
 * - 超时控制、工作目录、退出码与 stderr 捕获
 * - 网络类错误识别与自动重试
 * - 镜像/registry 注入
 * - composer.lock / 前端 lock 文件同步
 * - auto_install=false 时的「待执行命令清单」收集
 */
final class DependencyRunner
{
    /**
     * 网络类错误特征
     */
    private const NETWORK_ERROR_PATTERNS = [
        'could not resolve host',
        'temporary failure in name resolution',
        'connection timed out',
        'connection reset',
        'failed to connect',
        'network is unreachable',
        'cURL error',
        'etimedout',
        'econnreset',
        'econnrefused',
        'socket hang up',
        'request timed out',
        'read timed out',
        'timed out',
    ];

    /**
     * 依赖配置（config/plugin.php 的 dependencies 节点）
     */
    private array $config;

    /**
     * 输出回调
     *
     * @var callable
     */
    private $output;

    /**
     * 待执行命令清单
     */
    private array $pending = [];

    /**
     * 本次执行过程中的错误摘要
     */
    private string $lastError = '';

    public function __construct(array $config, ?callable $output = null)
    {
        $this->config = $config;
        $this->output = $output ?: static function (string $message): void {
        };
    }

    /**
     * 输出消息
     */
    private function output(string $message): void
    {
        ($this->output)($message);
    }

    /**
     * 最近一次错误摘要（供状态持久化记录）
     */
    public function getLastError(): string
    {
        return $this->lastError;
    }

    /**
     * 待执行命令清单
     */
    public function getPendingCommands(): array
    {
        return $this->pending;
    }

    /**
     * 清空待执行清单
     */
    public function resetPending(): void
    {
        $this->pending = [];
    }

    /**
     * 输出待执行命令清单
     */
    public function outputPendingCommands(): void
    {
        if (empty($this->pending)) {
            return;
        }

        $this->output("  📋 待手动执行的命令清单：");
        foreach ($this->pending as $item) {
            $this->output("     · [{$item['label']}] cd {$item['work_dir']} && {$item['command']}");
        }
    }

    /**
     * 登记待执行命令（不实际执行）
     */
    public function pending(string $command, string $workDir, string $label = ''): void
    {
        $this->pending[] = [
            'label'    => $label,
            'work_dir' => $workDir,
            'command'  => $command,
        ];
    }

    /**
     * 检测命令是否可用
     */
    public function isAvailable(string $bin): bool
    {
        if (!function_exists('proc_open')) {
            $this->output("  ⚠️ proc_open 被禁用，无法执行 {$bin}");
            return false;
        }

        $command = PHP_OS_FAMILY === 'Windows'
            ? 'where ' . escapeshellarg($bin)
            : 'command -v ' . escapeshellarg($bin);

        $result = $this->exec($command, null, "检测 {$bin}", 15);

        return $result;
    }

    /**
     * 解析可执行文件的真实 Windows 路径
     *
     * Windows 下 `where composer` 可能同时返回无扩展名的 unix 包装脚本与
     * composer.bat；裸名经 proc_open 执行会命中错误包装器（典型报错
     * "Could not open input file: composer.phar"），必须显式选择
     * Windows 可执行扩展（.bat/.cmd/.exe）。
     */
    private function resolveBin(string $bin): string
    {
        if (PHP_OS_FAMILY !== 'Windows' || preg_match('/\.(bat|cmd|exe|phar)$/i', $bin) || str_contains($bin, '\\') || str_contains($bin, '/')) {
            return $bin;
        }

        $result = @shell_exec('where ' . escapeshellarg($bin) . ' 2>nul');
        if (is_string($result) && $result !== '') {
            foreach (preg_split('/\r?\n/', trim($result)) ?: [] as $candidate) {
                $candidate = trim($candidate);
                if ($candidate !== '' && preg_match('/\.(bat|cmd|exe)$/i', $candidate)) {
                    return $candidate;
                }
            }
        }

        return $bin;
    }

    /**
     * 安装后端依赖（composer update 指定包）
     *
     * 前置条件：包声明已写入 composer.json
     *
     * @param array  $packages 包名 => 版本约束
     * @param string $workDir  工作目录（backend 根目录）
     */
    public function installBackend(array $packages, string $workDir): bool
    {
        if (empty($packages)) {
            return true;
        }

        $bin      = $this->resolveBin((string)$this->configValue('composer.bin', 'composer'));
        $timeout  = (int)$this->configValue('composer.timeout', 600);
        $packages = array_keys($packages);

        $command = escapeshellarg($bin) . ' update --no-interaction --no-scripts ';
        foreach ($packages as $package) {
            $command .= escapeshellarg($package) . ' ';
        }
        $command = rtrim($command);

        $this->applyComposerMirror($workDir);

        return $this->run($command, $workDir, 'Composer 依赖安装', $timeout);
    }

    /**
     * 移除后端依赖（composer remove）
     */
    public function removeBackend(array $packages, string $workDir): bool
    {
        if (empty($packages)) {
            return true;
        }

        $bin     = $this->resolveBin((string)$this->configValue('composer.bin', 'composer'));
        $timeout = (int)$this->configValue('composer.timeout', 600);

        $command = escapeshellarg($bin) . ' remove --no-interaction --no-scripts ';
        foreach ($packages as $package) {
            $command .= escapeshellarg($package) . ' ';
        }
        $command = rtrim($command);

        return $this->run($command, $workDir, 'Composer 依赖移除', $timeout);
    }

    /**
     * 安装前端依赖
     */
    public function installFrontend(string $workDir, string $endpoint): bool
    {
        $manager = $this->resolveBin($this->detectManager($workDir));
        $timeout = (int)$this->configValue('npm.timeout', 600);

        $command = escapeshellarg($manager) . ' install' . $this->registryFlag();

        return $this->run($command, $workDir, "{$endpoint} 前端依赖安装", $timeout);
    }

    /**
     * 移除前端依赖
     */
    public function removeFrontend(array $packages, string $workDir, string $endpoint): bool
    {
        if (empty($packages)) {
            return true;
        }

        $manager = $this->resolveBin($this->detectManager($workDir));
        $timeout = (int)$this->configValue('npm.timeout', 600);
        $remover = str_contains($manager, 'pnpm') ? 'remove' : 'uninstall';

        $command = escapeshellarg($manager) . ' ' . $remover . ' ';
        foreach (array_keys($packages) as $package) {
            $command .= escapeshellarg($package) . ' ';
        }
        $command = rtrim($command) . $this->registryFlag();

        return $this->run($command, $workDir, "{$endpoint} 前端依赖移除", $timeout);
    }

    /**
     * 同步 composer.lock（仅重算 hash 与 lock 元数据，不改变已锁定版本）
     */
    public function syncComposerLock(string $workDir): bool
    {
        $lockFile = rtrim($workDir, '/\\') . DIRECTORY_SEPARATOR . 'composer.lock';
        if (!is_file($lockFile)) {
            $this->output("  ℹ️ 未发现 composer.lock，跳过 lock 同步");
            return true;
        }

        $bin     = $this->resolveBin((string)$this->configValue('composer.bin', 'composer'));
        $timeout = (int)$this->configValue('composer.timeout', 600);

        $command = escapeshellarg($bin) . ' update --lock --no-interaction --no-scripts';

        return $this->run($command, $workDir, 'composer.lock 同步', $timeout);
    }

    /**
     * 同步前端 lock 文件（只更新 lock，不落地 node_modules）
     */
    public function syncFrontendLock(string $workDir, string $endpoint): bool
    {
        $manager = $this->resolveBin($this->detectManager($workDir));
        $timeout = (int)$this->configValue('npm.timeout', 600);

        $command = str_contains($manager, 'pnpm')
            ? escapeshellarg($manager) . ' install --lockfile-only'
            : escapeshellarg($manager) . ' install --package-lock-only --ignore-scripts';

        $command .= $this->registryFlag();

        return $this->run($command, $workDir, "{$endpoint} lock 文件同步", $timeout);
    }

    /**
     * 探测前端包管理器
     */
    public function detectManager(string $workDir): string
    {
        $configured = (string)$this->configValue('npm.manager', 'auto');

        if (in_array($configured, ['pnpm', 'npm'], true)) {
            return $configured;
        }

        // 依据 lock 文件与 packageManager 字段自动探测
        if (is_file($workDir . '/pnpm-lock.yaml')) {
            return 'pnpm';
        }
        if (is_file($workDir . '/yarn.lock')) {
            return 'npm';
        }

        $packageFile = $workDir . '/package.json';
        if (is_file($packageFile)) {
            $package = json_decode((string)file_get_contents($packageFile), true);
            if (is_array($package) && str_contains((string)($package['packageManager'] ?? ''), 'pnpm')) {
                return 'pnpm';
            }
        }

        return 'npm';
    }

    /**
     * 执行命令（带超时与网络重试）
     */
    public function run(string $command, string $workDir, string $label = '', int $timeout = 600): bool
    {
        $label = $label !== '' ? $label : 'Command';

        if (!is_dir($workDir)) {
            $this->output("  ⚠️ {$label}: 目录不存在 {$workDir}");
            $this->lastError = "目录不存在: {$workDir}";
            return false;
        }

        if (!function_exists('proc_open')) {
            $this->output("  ⚠️ {$label}: proc_open 被禁用，无法执行命令");
            $this->lastError = 'proc_open 被禁用';
            return false;
        }

        $retry      = max(0, (int)$this->configValue('network.retry', 2));
        $retrySleep = max(0, (int)$this->configValue('network.retry_sleep', 3));

        for ($attempt = 0; $attempt <= $retry; $attempt++) {
            $this->output("  🔧 {$label}: {$command}");

            [$exitCode, $stdout, $stderr] = $this->execWithTimeout($command, $workDir, $timeout);

            if ($exitCode === 0) {
                $this->lastError = '';
                $this->output("  ✅ {$label}: 执行成功");
                return true;
            }

            $error     = $this->summarizeError($stderr, $stdout);
            $isNetwork = $this->isNetworkError($stderr . "\n" . $stdout);
            $this->lastError = $error;

            $this->output("  ❌ {$label}: 执行失败（exit: {$exitCode}）");
            if ($error !== '') {
                $this->output("     {$error}");
            }

            if ($isNetwork && $attempt < $retry) {
                $this->output("  🌐 检测到网络类错误，{$retrySleep}s 后重试（" . ($attempt + 1) . "/{$retry}）");
                sleep($retrySleep);
                continue;
            }

            if ($isNetwork) {
                $this->output("  🌐 网络不可用，可配置镜像源后重试：dependencies.composer.mirror / dependencies.npm.registry");
            }

            return false;
        }

        return false;
    }

    /**
     * 执行单条命令并捕获输出（不重试）
     *
     * stdout/stderr 重定向到临时文件而非管道：
     * Windows 下 stream_set_blocking 对 proc_open 管道不可靠，顺序 fread 会
     * 阻塞在无数据的管道上（典型：composer update --lock 输出走 stderr、
     * stdout 全程无数据），导致轮询死锁、超时永不触发；文件重定向彻底规避。
     *
     * @return array{0: int, 1: string, 2: string} [exitCode, stdout, stderr]
     */
    private function execWithTimeout(string $command, ?string $workDir, int $timeout): array
    {
        $outFile = (string)tempnam(sys_get_temp_dir(), 'madong_dep_out_');
        $errFile = (string)tempnam(sys_get_temp_dir(), 'madong_dep_err_');

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $outFile, 'w'],
            2 => ['file', $errFile, 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, $workDir);
        if (!is_resource($process)) {
            @unlink($outFile);
            @unlink($errFile);
            return [1, '', 'proc_open 调用失败'];
        }

        // 立即关闭 stdin：命令均为非交互式，保持打开会让部分子进程等待 EOF
        fclose($pipes[0]);

        $stdout   = '';
        $stderr   = '';
        $outBuf   = ''; // stdout 行缓冲（用于实时转发完整行）
        $errBuf   = ''; // stderr 行缓冲
        $outPos   = 0;
        $errPos   = 0;
        $deadline = time() + max(1, $timeout);
        $killed   = false;

        while (true) {
            $status = proc_get_status($process);

            $outPos += $this->drainNewBytes($outFile, $outPos, $stdout, $outBuf);
            $errPos += $this->drainNewBytes($errFile, $errPos, $stderr, $errBuf);

            if (!$status['running']) {
                // 进程退出后最后冲刷一次，确保读到全部尾部输出
                $this->drainNewBytes($outFile, $outPos, $stdout, $outBuf);
                $this->drainNewBytes($errFile, $errPos, $stderr, $errBuf);
                break;
            }

            if (time() > $deadline) {
                $killed = true;
                $this->killProcessTree($process);
                break;
            }

            usleep(200000);
        }

        // 冲刷行缓冲中残留的最后一行
        $this->emitStreamLines("\n", $outBuf);
        $this->emitStreamLines("\n", $errBuf);

        $exitCode = proc_close($process);

        @unlink($outFile);
        @unlink($errFile);

        if ($killed) {
            $stderr .= "\n执行超时（超过 {$timeout}s），已终止进程";
            return [124, $stdout, $stderr];
        }

        return [$exitCode, $stdout, $stderr];
    }

    /**
     * 读取临时文件自 $pos 起的新增字节：追加到 $sink 并按完整行转发
     *
     * @return int 本次读取的字节数
     */
    private function drainNewBytes(string $file, int $pos, string &$sink, string &$lineBuf): int
    {
        clearstatcache(true, $file);
        $size = @filesize($file);
        if ($size === false || $size <= $pos) {
            return 0;
        }

        $fp = @fopen($file, 'rb');
        if ($fp === false) {
            return 0;
        }
        fseek($fp, $pos);

        $chunk = '';
        while (!feof($fp)) {
            $part = fread($fp, 65536);
            if ($part === false || $part === '') {
                break;
            }
            $chunk .= $part;
        }
        fclose($fp);

        if ($chunk === '') {
            return 0;
        }

        $sink .= $chunk;
        $this->emitStreamLines($chunk, $lineBuf);

        return strlen($chunk);
    }

    /**
     * 强杀进程树（含子孙进程）
     */
    private function killProcessTree($process): void
    {
        $status = proc_get_status($process);
        $pid    = (int)($status['pid'] ?? 0);

        if (PHP_OS_FAMILY === 'Windows' && $pid > 0) {
            // proc_terminate 只能杀死直接进程（cmd.exe 包装器），孙进程
            // （php.exe/composer.phar）会残留，必须整树强杀
            @shell_exec('taskkill /F /T /PID ' . $pid);
        }

        @proc_terminate($process, 9);
    }

    /**
     * 简单执行（仅判断成功失败，用于命令存在性检测）
     */
    private function exec(string $command, ?string $workDir, string $label = '', int $timeout = 30): bool
    {
        [$exitCode, , ] = $this->execWithTimeout($command, $workDir, $timeout);
        return $exitCode === 0;
    }

    /**
     * 将子进程新增输出按完整行实时转发到 output 回调
     */
    private function emitStreamLines(string $chunk, string &$buffer): void
    {
        $buffer .= $chunk;

        // 转发所有完整行
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line   = trim((string)substr($buffer, 0, $pos));
            $buffer = (string)substr($buffer, $pos + 1);
            $line   = $this->cleanStreamLine($line);
            if ($line !== '') {
                $this->output('  │ ' . $line);
            }
        }

        // 无换行的超长内容视为进度条刷屏，直接丢弃
        if (strlen($buffer) > 16384) {
            $buffer = '';
        }
    }

    /**
     * 清理单行输出（去 ANSI 控制序列 / \r 进度条残留 / 截断超长行）
     */
    private function cleanStreamLine(string $line): string
    {
        $line = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $line) ?? $line;
        if (($pos = strrpos($line, "\r")) !== false) {
            $line = substr($line, $pos + 1);
        }
        $line = trim($line);

        return mb_substr($line, 0, 300);
    }

    /**
     * 应用 Composer 镜像源（配置为空时不处理）
     */
    private function applyComposerMirror(string $workDir): void
    {
        $mirror = (string)$this->configValue('composer.mirror', '');
        if ($mirror === '') {
            return;
        }

        $bin     = $this->resolveBin((string)$this->configValue('composer.bin', 'composer'));
        $command = escapeshellarg($bin) . ' config -g repositories.packagist composer ' . escapeshellarg($mirror);

        $this->run($command, $workDir, 'Composer 镜像源配置', 120);
    }

    /**
     * registry 命令行参数
     */
    private function registryFlag(): string
    {
        $registry = (string)$this->configValue('npm.registry', '');
        if ($registry === '') {
            return '';
        }

        return ' --registry=' . escapeshellarg($registry);
    }

    /**
     * 判断是否为网络类错误
     */
    private function isNetworkError(string $output): bool
    {
        $lower = strtolower($output);

        foreach (self::NETWORK_ERROR_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 提取错误摘要（截断，避免大 payload）
     */
    private function summarizeError(string $stderr, string $stdout, int $limit = 500): string
    {
        $source = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
        if ($source === '') {
            return '';
        }

        $lines = array_filter(array_map('trim', explode("\n", $source)));
        $error = implode(' | ', array_slice($lines, -3));

        return mb_substr($error, 0, $limit);
    }

    /**
     * 读取配置值（支持点号路径）
     */
    private function configValue(string $key, mixed $default = null): mixed
    {
        $value = $this->config;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
