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
use core\io\upload\UploadFile;
use Qiniu\Auth;
use Qiniu\Storage\BucketManager;
use Qiniu\Storage\UploadManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use support\Db;
use support\Log;

/**
 * 七牛云全量迁移命令
 *
 * 把 public/{dir} 下的历史文件按「原路径」上传到七牛云，并把历史 URL 改写为 CDN 域名。
 *
 * ┌─ 使用示例 ─────────────────────────────────────────────────────┐
 * │ php webman upload:migrate-qiniu --dry-run                      │ 预演（不写任何数据）
 * │ php webman upload:migrate-qiniu                                │ 全量执行（文件 + 数据库）
 * │ php webman upload:migrate-qiniu --skip-files                   │ 只改数据库
 * │ php webman upload:migrate-qiniu --hosts=old.com,www.old.com    │ 手工追加主机白名单
 * │ php webman upload:migrate-qiniu --report-orphans               │ 盘点桶内孤儿对象（只报告）
 * └───────────────────────────────────────────────────────────────┘
 *
 * @author Mr.April
 * @since 1.0.0
 */
#[AsCommand(
    name: 'upload:migrate-qiniu',
    description: 'Migrate local upload files and historical URLs to Qiniu Cloud',
    aliases: ['upload:migrate-qiniu'],
    hidden: false
)]
class MigrateQiniuCommand extends BaseCommand
{
    /** 数据库阶段每批处理行数 */
    private const CHUNK = 500;

    /** 跳过扫描的噪音表（不含表前缀） */
    private const SKIP_TABLES = ['sys_operate_log', 'sys_login_log', 'sys_recycle_bin'];

    /** 参与扫描的文本列类型 */
    private const TEXT_TYPES = ['char', 'varchar', 'text', 'mediumtext', 'longtext'];

    private bool $dryRun = false;
    private bool $force = false;
    private string $dir = 'upload';
    private string $dirname = 'upload';
    private string $domain = '';
    private string $bucket = '';
    private string $tablePrefix = '';
    private array $hosts = [];
    private array $tablesFilter = [];
    private string $reportDir = '';

    private Auth $auth;
    private UploadManager $uploadManager;
    private BucketManager $bucketManager;

    private array $fileStats = ['discovered' => 0, 'bytes' => 0, 'uploaded' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
    private array $dbStats = ['tables' => 0, 'columns' => 0, 'scanned' => 0, 'changed' => 0, 'unchanged' => 0, 'unchanged_samples' => [], 'unchanged_keys' => [], 'warnings' => [], 'samples' => [], 'sample_keys' => [], 'details' => []];

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, '只预演：统计文件与待改行，不写入任何数据')
            ->addOption('dir', null, InputOption::VALUE_OPTIONAL, 'public 下的迁移子目录', 'upload')
            ->addOption('hosts', null, InputOption::VALUE_OPTIONAL, '自有主机白名单（逗号分隔），用于历史 URL 域名替换', '')
            ->addOption('tables', null, InputOption::VALUE_OPTIONAL, '限定扫描的表（不含表前缀，逗号分隔）', '')
            ->addOption('skip-files', null, InputOption::VALUE_NONE, '跳过文件上传阶段')
            ->addOption('skip-db', null, InputOption::VALUE_NONE, '跳过数据库改写阶段')
            ->addOption('report-orphans', null, InputOption::VALUE_NONE, '只盘点桶内孤儿对象（本地无文件且 DB 无引用），不写不删')
            ->addOption('force', null, InputOption::VALUE_NONE, '对象已存在时也覆盖上传');
    }

    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('七牛云全量迁移');

        $this->dryRun = (bool)$input->getOption('dry-run');
        $this->force  = (bool)$input->getOption('force');
        $this->dir    = trim((string)$input->getOption('dir'), '/') ?: 'upload';

        $tables = trim((string)$input->getOption('tables'));
        $this->tablesFilter = $tables === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $tables))));

        try {
            $this->bootstrap();
        } catch (\Throwable $e) {
            return $this->outputError($io, $e->getMessage(), $e);
        }

        $this->hosts = $this->resolveHosts((string)$input->getOption('hosts'));

        $this->reportDir = runtime_path() . '/migrate';
        if (!is_dir($this->reportDir) && !mkdir($this->reportDir, 0755, true) && !is_dir($this->reportDir)) {
            return $this->outputError($io, '无法创建报告目录: ' . $this->reportDir);
        }

        $io->text(sprintf('存储桶: <info>%s</info>   CDN: <info>%s</info>   根目录: <info>%s</info>', $this->bucket, $this->domain, $this->dirname));
        $io->text(sprintf('运行模式: %s', $this->dryRun ? '<comment>dry-run（不写入）</comment>' : '<info>实际执行</info>'));
        $io->text('白名单主机: ' . ($this->hosts ? implode(', ', $this->hosts) : '（无；仅处理 /upload/ 绝对路径）'));

        try {
            if ($input->getOption('report-orphans')) {
                return $this->reportOrphans($io);
            }
            if (!$input->getOption('skip-files')) {
                $this->migrateFiles($io);
            }
            if (!$input->getOption('skip-db')) {
                $this->migrateDatabase($io);
            }
        } catch (\Throwable $e) {
            return $this->outputError($io, '迁移中断: ' . $e->getMessage(), $e);
        }

        $reportFile = $this->writeReport();
        $this->printSummary($io, $reportFile);

        return self::SUCCESS;
    }

    /**
     * 读取七牛配置并初始化 SDK
     *
     * @throws \Exception
     */
    private function bootstrap(): void
    {
        $config = UploadFile::config('qiniu', []);
        if (empty($config['accessKey']) || empty($config['secretKey']) || empty($config['bucket'])) {
            throw new \Exception('七牛配置不完整，请先在后台「系统配置 - 存储配置」填写 accessKey / secretKey / bucket');
        }
        if (empty($config['domain'])) {
            throw new \Exception('七牛配置缺少 domain（CDN 域名）');
        }

        $this->bucket = (string)$config['bucket'];
        $this->domain = rtrim((string)$config['domain'], '/');

        // 对象根目录以「配置」为准；--dir 只决定本地扫描目录，两者不一致时以配置为准并告警
        $this->dirname       = trim((string)($config['dirname'] ?? $this->dir), '/') ?: $this->dir;
        $this->tablePrefix   = (string)Db::connection()->getTablePrefix();
        $this->auth          = new Auth((string)$config['accessKey'], (string)$config['secretKey']);
        $this->uploadManager = new UploadManager();
        $this->bucketManager = new BucketManager($this->auth);
    }

    /**
     * 解析主机白名单：--hosts ∪ md_sys_upload.url 的域名分布 ∪ APP_URL
     */
    private function resolveHosts(string $option): array
    {
        $hosts = [];
        foreach (explode(',', $option) as $host) {
            $host = trim($host);
            if ($host !== '') {
                $hosts[] = preg_replace('#^https?://#i', '', rtrim($host, '/'));
            }
        }

        try {
            $rows = Db::select("SELECT DISTINCT SUBSTRING_INDEX(SUBSTRING_INDEX(`url`, '/', 3), '/', -1) AS host
                                FROM `{$this->tablePrefix}sys_upload` WHERE `url` LIKE 'http%'");
            foreach ($rows as $row) {
                if (!empty($row->host)) {
                    $hosts[] = (string)$row->host;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('upload:migrate-qiniu 解析历史主机失败: ' . $e->getMessage());
        }

        $appUrl = (string)(env('APP_URL') ?: config('app.app_host', ''));
        if ($appUrl !== '') {
            $hosts[] = preg_replace('#^https?://#i', '', rtrim($appUrl, '/'));
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    // ═══════════════════════ 文件阶段 ═══════════════════════

    private function migrateFiles(SymfonyStyle $io): void
    {
        $io->section('阶段 1/2：本地文件 → 七牛');

        $absDir = public_path() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $this->dir);
        if (!is_dir($absDir)) {
            $io->warning('本地目录不存在，跳过文件阶段: ' . $absDir);
            return;
        }

        $files = $this->scanFiles($absDir);
        $bytes = array_sum(array_column($files, 'size'));
        $io->text(sprintf('发现 <info>%d</info> 个文件，共 %s', count($files), formatBytes($bytes)));

        if ($this->dryRun) {
            $this->fileStats['discovered'] = count($files);
            $this->fileStats['bytes']      = $bytes;
            $io->text('dry-run：仅统计，不执行上传。样例（前 5 条）：');
            foreach (array_slice($files, 0, 5) as $file) {
                $io->text(sprintf('  %s  →  %s', $file['key'], formatBytes($file['size'])));
            }
            return;
        }

        $token = $this->auth->uploadToken($this->bucket);
        foreach ($files as $file) {
            $this->fileStats['discovered']++;
            $this->fileStats['bytes'] += $file['size'];

            if (!$this->force && $this->objectExists($file['key'])) {
                $this->fileStats['skipped']++;
                continue;
            }

            try {
                if ($file['size'] === 0) {
                    // 零字节占位文件：SDK 的 putFile() 内部会执行 fread($file, 0) 而抛错，改用二进制流上传空内容
                    [$ret, $err] = $this->uploadManager->put($token, $file['key'], '', null, 'application/octet-stream');
                } else {
                    // putFile 内部在 >4MB 时自动切换分片上传，无需显式判断
                    [$ret, $err] = $this->uploadManager->putFile($token, $file['key'], $file['path']);
                }
                if ($err) {
                    throw new \RuntimeException((string)json_encode($err));
                }
                if (!$this->verifyObject($file['key'], $file['size'])) {
                    throw new \RuntimeException(sprintf('校验失败：云端大小与本地 %s 不一致', formatBytes($file['size'])));
                }
                $this->fileStats['uploaded']++;
            } catch (\Throwable $e) {
                $this->fileStats['failed']++;
                $this->fileStats['errors'][] = ['key' => $file['key'], 'path' => $file['path'], 'error' => $e->getMessage()];
                $io->text(sprintf('<error>✗</error> %s  %s', $file['key'], $e->getMessage()));
            }
        }

        $io->text(sprintf(
            '文件阶段完成：上传 %d，跳过 %d，失败 %d',
            $this->fileStats['uploaded'],
            $this->fileStats['skipped'],
            $this->fileStats['failed']
        ));
    }

    /**
     * 递归扫描本地目录，返回 [['key','path','size'], ...]
     */
    private function scanFiles(string $absDir): array
    {
        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($absDir) + 1));
            if ($relative === '' || str_starts_with($relative, '.git/') || str_contains($relative, '/.git/')) {
                continue;
            }
            $files[] = [
                'key'  => $this->dirname . '/' . $relative,
                'path' => $item->getPathname(),
                'size' => (int)$item->getSize(),
            ];
        }

        usort($files, static fn($a, $b) => strcmp($a['key'], $b['key']));
        return $files;
    }

    private function objectExists(string $key): bool
    {
        [, $err] = $this->bucketManager->stat($this->bucket, $key);
        return $err === null;
    }

    private function verifyObject(string $key, int $localSize): bool
    {
        [$ret, $err] = $this->bucketManager->stat($this->bucket, $key);
        if ($err || !isset($ret['fsize'])) {
            return false;
        }
        return (int)$ret['fsize'] === $localSize;
    }

    // ═══════════════════════ 数据库阶段 ═══════════════════════

    private function migrateDatabase(SymfonyStyle $io): void
    {
        $io->section('阶段 2/2：历史 URL → CDN 域名');

        $columns = $this->discoverTextColumns();
        if (empty($columns)) {
            $io->warning('未发现包含 upload/ 的文本列');
            return;
        }

        $this->dbStats['columns'] = count($columns);
        $byTable = [];
        foreach ($columns as $column) {
            $byTable[$column['table']][] = $column;
        }
        $this->dbStats['tables'] = count($byTable);

        $io->text(sprintf('命中 <info>%d</info> 张表 / <info>%d</info> 个列', $this->dbStats['tables'], $this->dbStats['columns']));

        $sysUploadPk = null;
        foreach ($byTable as $table => $tableColumns) {
            if (in_array($table, self::SKIP_TABLES, true)) {
                $this->dbStats['warnings'][] = "跳过噪音表: {$table}";
                continue;
            }
            // md_sys_upload 单独处理（path / base_path 保持不动，platform 需切换）
            if ($table === 'sys_upload') {
                $sysUploadPk = $tableColumns[0]['pk'];
                continue;
            }
            foreach ($tableColumns as $column) {
                $this->rewriteColumn($table, $column['pk'], $column['column']);
            }
        }

        if ($sysUploadPk !== null) {
            $this->handleSysUpload($sysUploadPk);
        }

        $io->text(sprintf(
            '数据库阶段完成：扫描 %d 行，改写 %d 行，无需改写 %d 行',
            $this->dbStats['scanned'],
            $this->dbStats['changed'],
            $this->dbStats['unchanged']
        ));
    }

    /**
     * 发现「包含 upload/ 且所属表有主键」的文本列
     *
     * @return array<int, array{table:string, column:string, pk:string}>
     */
    private function discoverTextColumns(): array
    {
        $types  = "'" . implode("','", self::TEXT_TYPES) . "'";
        $schema = "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE IN ({$types})";
        $rows = Db::select($schema);

        $pkMap = [];
        foreach (Db::select("SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE
                             WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'PRIMARY'
                             ORDER BY TABLE_NAME, ORDINAL_POSITION") as $row) {
            $pkMap[$row->t] ??= (string)$row->c;
        }

        $candidates = [];
        $tables     = [];
        foreach ($rows as $row) {
            $table = (string)$row->t;
            if (!str_starts_with($table, $this->tablePrefix)) {
                continue;
            }
            $bare = substr($table, strlen($this->tablePrefix));
            if (!empty($this->tablesFilter) && !in_array($bare, $this->tablesFilter, true)) {
                continue;
            }
            if (!isset($pkMap[$table])) {
                continue;
            }
            $tables[$bare] = $table;
            $candidates[]  = ['bare' => $bare, 'table' => $table, 'column' => (string)$row->c, 'pk' => $pkMap[$table]];
        }

        // 只保留真正存在 upload/ 数据的表，避免无谓全表扫描
        $hits = [];
        foreach ($candidates as $candidate) {
            $sql = sprintf(
                'SELECT COUNT(*) AS n FROM `%s` WHERE `%s` LIKE ?',
                $this->safeIdentifier($candidate['table']),
                $this->safeIdentifier($candidate['column'])
            );
            $count = (int)(Db::select($sql, ['%upload/%'])[0]->n ?? 0);
            if ($count > 0) {
                $hits[] = [
                    'table'  => $candidate['bare'],
                    'column' => $candidate['column'],
                    'pk'     => $candidate['pk'],
                    'count'  => $count,
                ];
            }
        }

        return $hits;
    }

    /**
     * 按主键分块改写单列
     */
    private function rewriteColumn(string $table, string $pk, string $column): void
    {
        $realTable = $this->safeIdentifier($this->tablePrefix . $table);
        $pkCol     = $this->safeIdentifier($pk);
        $valCol    = $this->safeIdentifier($column);

        $lastPk = null;
        while (true) {
            $params = ['%upload/%'];
            $sql    = "SELECT `{$pkCol}` AS __pk, `{$valCol}` AS __val FROM `{$realTable}` WHERE `{$valCol}` LIKE ?";
            if ($lastPk !== null) {
                $sql     .= " AND `{$pkCol}` > ?";
                $params[] = $lastPk;
            }
            $sql .= " ORDER BY `{$pkCol}` ASC LIMIT " . self::CHUNK;

            $rows = Db::select($sql, $params);
            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $lastPk = $row->__pk;
                $this->dbStats['scanned']++;

                $old = $row->__val;
                if (!is_string($old) || $old === '') {
                    continue;
                }
                $new = $this->rewrite($old);
                if ($new === $old) {
                    $this->recordUnchanged($table, $column, $old);
                    continue;
                }

                $this->dbStats['changed']++;
                $this->recordSample($table, $column, $old, $new);

                if (!$this->dryRun) {
                    Db::table($table)->where($pk, $row->__pk)->update([$column => $new]);
                }
            }

            if (count($rows) < self::CHUNK) {
                break;
            }
        }
    }

    /**
     * md_sys_upload 专有处理：url / th_url 走改写规则，platform 由 local 改为 qiniu
     */
    private function handleSysUpload(string $pk): void
    {
        $realTable = $this->safeIdentifier($this->tablePrefix . 'sys_upload');
        $pkCol     = $this->safeIdentifier($pk);

        $lastPk = null;
        while (true) {
            $params = [];
            $sql    = "SELECT `{$pkCol}` AS __pk, `url` AS u, `th_url` AS t, `platform` AS p
                       FROM `{$realTable}` WHERE `url` LIKE ?";
            $params[] = '%upload/%';
            if ($lastPk !== null) {
                $sql     .= " AND `{$pkCol}` > ?";
                $params[] = $lastPk;
            }
            $sql .= " ORDER BY `{$pkCol}` ASC LIMIT " . self::CHUNK;

            $rows = Db::select($sql, $params);
            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $lastPk = $row->__pk;
                $this->dbStats['scanned']++;

                $update = [];
                foreach (['url' => 'u', 'th_url' => 't'] as $column => $alias) {
                    $old = $row->{$alias};
                    if (!is_string($old) || $old === '') {
                        continue;
                    }
                    $new = $this->rewrite($old);
                    if ($new !== $old) {
                        $update[$column] = $new;
                        $this->recordSample('sys_upload', $column, $old, $new);
                    } else {
                        $this->recordUnchanged('sys_upload', $column, $old);
                    }
                }
                if ($row->p === 'local') {
                    $update['platform'] = 'qiniu';
                }
                if (empty($update)) {
                    continue;
                }

                $this->dbStats['changed']++;
                if (!$this->dryRun) {
                    Db::table('sys_upload')->where($pk, $row->__pk)->update($update);
                }
            }

            if (count($rows) < self::CHUNK) {
                break;
            }
        }
    }

    /**
     * 历史 URL 改写（三步，顺序不可调整）
     *
     * 1) 自有主机白名单的绝对/协议相对 URL（后随 /upload/）→ CDN
     * 2) public/upload/ → upload/
     * 3) 其余以 /upload/ 开头（前一字符不是 [A-Za-z0-9:/]）→ {cdn}/upload/
     */
    private function rewrite(string $value): string
    {
        if ($value === '' || !str_contains($value, 'upload/')) {
            return $value;
        }

        if (!empty($this->hosts)) {
            $hosts = implode('|', array_map(static fn($host) => preg_quote($host, '#'), $this->hosts));
            $value = (string)preg_replace(
                '#(?<![\w.-])(?:https?:)?//(?:' . $hosts . ')(?=/upload/)#i',
                $this->domain,
                $value
            );
        }

        $value = str_replace('public/upload/', 'upload/', $value);

        return (string)preg_replace('#(?<![A-Za-z0-9:/])/upload/#', $this->domain . '/upload/', $value);
    }

    private function recordSample(string $table, string $column, string $old, string $new): void
    {
        $key = $table . '.' . $column;
        if (!isset($this->dbStats['details'][$key])) {
            $this->dbStats['details'][$key] = ['table' => $table, 'column' => $column, 'count' => 0];
        }
        $this->dbStats['details'][$key]['count']++;

        // 同一「原值」只保留一条样例，避免同类数据刷屏
        $fingerprint = md5($key . '|' . $old);
        if (isset($this->dbStats['sample_keys'][$fingerprint])) {
            return;
        }
        $this->dbStats['sample_keys'][$fingerprint] = true;

        if (count($this->dbStats['samples']) >= 40) {
            return;
        }
        $this->dbStats['samples'][] = [
            'table'  => $table,
            'column' => $column,
            'before' => mb_substr($old, 0, 160),
            'after'  => mb_substr($new, 0, 160),
        ];
    }

    /**
     * 记录「命中 upload/ 但无需改写」的行，用于排查漏改
     *
     * 典型场景：值里出现 upload/ 但前面紧邻字母/数字（如 xxx0/upload/），
     * 说明不是可识别的资源路径；也可能是纯文本说明中提到了 upload/。
     */
    private function recordUnchanged(string $table, string $column, string $old): void
    {
        $this->dbStats['unchanged']++;

        $key         = $table . '.' . $column;
        $fingerprint = md5($key . '|' . $old);
        if (isset($this->dbStats['unchanged_keys'][$fingerprint])) {
            return;
        }
        $this->dbStats['unchanged_keys'][$fingerprint] = true;

        if (count($this->dbStats['unchanged_samples']) >= 12) {
            return;
        }
        $this->dbStats['unchanged_samples'][] = [
            'table'  => $table,
            'column' => $column,
            'value'  => mb_substr($old, 0, 160),
        ];
    }

    // ═══════════════════════ 孤儿盘点 ═══════════════════════

    private function reportOrphans(SymfonyStyle $io): int
    {
        $io->section('孤儿对象盘点（只报告，不删除）');

        $prefix  = $this->dirname . '/';
        $objects = [];
        $marker  = null;
        do {
            [$ret, $err] = $this->bucketManager->listFiles($this->bucket, $prefix, $marker, 1000);
            if ($err) {
                return $this->outputError($io, '列举云对象失败: ' . json_encode($err, JSON_UNESCAPED_UNICODE));
            }
            foreach ($ret['items'] ?? [] as $item) {
                $objects[(string)$item['key']] = (int)($item['fsize'] ?? 0);
            }
            $marker = $ret['marker'] ?? null;
        } while (!empty($marker));

        $local = [];
        $absDir = public_path() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $this->dir);
        if (is_dir($absDir)) {
            foreach ($this->scanFiles($absDir) as $file) {
                $local[$file['key']] = true;
            }
        }

        $referenced = $this->collectReferencedKeys();

        $orphans = [];
        foreach ($objects as $key => $size) {
            if (isset($local[$key]) || isset($referenced[$key])) {
                continue;
            }
            $orphans[$key] = $size;
        }

        $file = $this->reportDir . '/orphans-' . date('YmdHis') . '.json';
        file_put_contents($file, json_encode([
            'generated_at' => date('Y-m-d H:i:s'),
            'bucket'       => $this->bucket,
            'prefix'       => $prefix,
            'cloud_total'  => count($objects),
            'local_total'  => count($local),
            'db_ref_total' => count($referenced),
            'orphans'      => $orphans,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $io->text(sprintf('云端对象 %d，本地文件 %d，DB 引用 %d', count($objects), count($local), count($referenced)));
        $io->text(sprintf('孤儿对象 <comment>%d</comment> 个，共 %s', count($orphans), formatBytes(array_sum($orphans))));
        $io->note('报告已写入: ' . $file . '（确认后用 upload:clean-prefix 定向清理）');

        return self::SUCCESS;
    }

    /**
     * 汇总数据库中出现的 upload/ 对象 key（用于孤儿比对）
     */
    private function collectReferencedKeys(): array
    {
        $keys = [];
        foreach ($this->discoverTextColumns() as $column) {
            $realTable = $this->safeIdentifier($this->tablePrefix . $column['table']);
            $valCol    = $this->safeIdentifier($column['column']);
            $lastPk    = null;

            while (true) {
                $params = ['%upload/%'];
                $sql    = "SELECT `{$valCol}` AS __val, `" . $this->safeIdentifier($column['pk']) . "` AS __pk
                           FROM `{$realTable}` WHERE `{$valCol}` LIKE ?";
                if ($lastPk !== null) {
                    $sql     .= " AND `" . $this->safeIdentifier($column['pk']) . "` > ?";
                    $params[] = $lastPk;
                }
                $sql .= " ORDER BY `" . $this->safeIdentifier($column['pk']) . "` ASC LIMIT " . self::CHUNK;

                $rows = Db::select($sql, $params);
                if (empty($rows)) {
                    break;
                }
                foreach ($rows as $row) {
                    $lastPk = $row->__pk;
                    if (!is_string($row->__val)) {
                        continue;
                    }
                    if (preg_match_all('#(?<![A-Za-z0-9:/])upload/[^\s"\'<>()\[\],;]+#', $row->__val, $matches)) {
                        foreach ($matches[0] as $key) {
                            $keys[$key] = true;
                        }
                    }
                }
                if (count($rows) < self::CHUNK) {
                    break;
                }
            }
        }

        return $keys;
    }

    // ═══════════════════════ 通用工具 ═══════════════════════

    private function safeIdentifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_$]+$/', $name)) {
            throw new \RuntimeException('非法的表名/列名: ' . $name);
        }
        return $name;
    }

    private function writeReport(): string
    {
        $file = $this->reportDir . '/qiniu-' . date('YmdHis') . '.json';
        file_put_contents($file, json_encode([
            'generated_at' => date('Y-m-d H:i:s'),
            'dry_run'      => $this->dryRun,
            'bucket'       => $this->bucket,
            'cdn'          => $this->domain,
            'dirname'      => $this->dirname,
            'hosts'        => $this->hosts,
            'files'        => $this->fileStats,
            'database'     => $this->dbStats,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $file;
    }

    private function printSummary(SymfonyStyle $io, string $reportFile): void
    {
        $io->section('迁移汇总');

        $io->table(['阶段', '指标', '值'], [
            ['文件', '发现', (string)$this->fileStats['discovered']],
            ['文件', '总大小', formatBytes($this->fileStats['bytes'])],
            ['文件', '已上传', (string)$this->fileStats['uploaded']],
            ['文件', '已跳过（云端已存在）', (string)$this->fileStats['skipped']],
            ['文件', '失败', (string)$this->fileStats['failed']],
            ['数据库', '命中表 / 列', $this->dbStats['tables'] . ' / ' . $this->dbStats['columns']],
            ['数据库', '扫描行', (string)$this->dbStats['scanned']],
            ['数据库', '改写行', (string)$this->dbStats['changed']],
            ['数据库', '无需改写（含 upload/ 但不构成资源路径）', (string)$this->dbStats['unchanged']],
        ]);

        if (!empty($this->dbStats['details'])) {
            $rows = [];
            foreach ($this->dbStats['details'] as $detail) {
                $rows[] = [$detail['table'], $detail['column'], (string)$detail['count']];
            }
            $io->text('改写明细（按表/列）：');
            $io->table(['表', '列', '改写行数'], $rows);
        }

        if (!empty($this->dbStats['samples'])) {
            $io->text('改写样例（同类原值只保留一条）：');
            foreach (array_slice($this->dbStats['samples'], 0, 20) as $sample) {
                $io->text(sprintf('  [%s.%s] %s  →  %s', $sample['table'], $sample['column'], $sample['before'], $sample['after']));
            }
        }

        if (!empty($this->dbStats['unchanged_samples'])) {
            $io->text('无需改写样例（确认是否为非资源路径文本）：');
            foreach ($this->dbStats['unchanged_samples'] as $sample) {
                $io->text(sprintf('  [%s.%s] %s', $sample['table'], $sample['column'], $sample['value']));
            }
        }

        foreach ($this->dbStats['warnings'] as $warning) {
            $io->warning($warning);
        }

        $io->note('报告已写入: ' . $reportFile);
        if ($this->dryRun) {
            $io->success('dry-run 结束，未写入任何数据。确认无误后去掉 --dry-run 再执行一次。');
        } else {
            $io->success('迁移完成。若全部成功，可把 md_sys_config 的 upload.mode 切换为 qiniu 并重启 webman。');
        }
    }
}