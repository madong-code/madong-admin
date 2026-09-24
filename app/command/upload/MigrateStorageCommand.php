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
use app\model\system\config\Upload;
use core\io\upload\UploadFile;
use core\io\upload\contract\UploadFileInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use support\Db;
use support\Log;

/**
 * 本地文件全量迁移命令（云厂商无关）
 *
 * 把 public/{dir} 下的历史文件上传到「当前上传配置所指向的存储」，并补齐 sys_upload
 * 附件记录、把历史 URL 改写为对应的访问地址。
 *
 * 读写全部经存储驱动抽象（UploadFile::disk）完成，命令自身不引用任何云厂商 SDK，
 * 因此 qiniu / oss / cos / s3 通用。驱动可选实现 exists() / listObjects() 两个能力，
 * 未实现时命令自动降级（放弃「跳过已存在」与「孤儿盘点」），不影响迁移主流程。
 *
 * 对象根目录以「存储配置的 dirname」为准（本库为 storage），本地目录名（--dir，默认 upload）
 * 只用于扫描：同一对象在本地是 public/upload/xxx、在云端是 {dirname}/xxx，
 * 因此历史引用 /upload/xxx 会一并改写为 {cdn}/storage/xxx。
 *
 * ┌─ 使用示例 ─────────────────────────────────────────────────────┐
 * │ php webman upload:migrate-storage --dry-run                    │ 预演（不写任何数据）
 * │ php webman upload:migrate-storage                              │ 全量执行（文件+记录+数据库）│
 * │ php webman upload:migrate-storage --platform=oss               │ 指定驱动（默认取上传配置）│
 * │ php webman upload:migrate-storage --skip-files                 │ 跳过文件上传│
 * │ php webman upload:migrate-storage --skip-records               │ 跳过附件记录补建│
 * │ php webman upload:migrate-storage --skip-db                    │ 跳过历史 URL 改写│
 * │ php webman upload:migrate-storage --hosts=old.com,www.old.com  │ 手工追加主机白名单│
 * │ php webman upload:migrate-storage --report-orphans             │ 盘点桶内孤儿对象（只报告）│
 * └───────────────────────────────────────────────────────────────┘
 *
 * @author Mr.April
 * @since 1.0.0
 */
#[AsCommand(
    name: 'upload:migrate-storage',
    description: 'Migrate local upload files and historical URLs to the configured cloud storage',
    hidden: false
)]
class MigrateStorageCommand extends BaseCommand
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
    private string $platform = '';
    private string $tablePrefix = '';
    private array $hosts = [];
    private array $tablesFilter = [];
    private string $reportDir = '';

    private UploadFileInterface $disk;

    /** 驱动是否支持 exists()：null=未探测，false=不支持（降级为直接覆盖上传） */
    private ?bool $supportsExists = null;

    private array $fileStats = ['discovered' => 0, 'bytes' => 0, 'uploaded' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
    private array $recordStats = ['scanned' => 0, 'created' => 0, 'existing' => 0, 'skipped' => 0, 'failed' => 0, 'by_source' => [], 'samples' => [], 'errors' => []];
    private array $dbStats = ['tables' => 0, 'columns' => 0, 'scanned' => 0, 'changed' => 0, 'unchanged' => 0, 'unchanged_samples' => [], 'unchanged_keys' => [], 'warnings' => [], 'samples' => [], 'sample_keys' => [], 'details' => []];

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, '只预演：统计文件与待改行，不写入任何数据')
            ->addOption('dir', null, InputOption::VALUE_OPTIONAL, 'public 下的迁移子目录', 'upload')
            ->addOption('platform', null, InputOption::VALUE_OPTIONAL, '存储驱动（qiniu/oss/cos/s3），默认取「上传配置」的 mode', '')
            ->addOption('hosts', null, InputOption::VALUE_OPTIONAL, '自有主机白名单（逗号分隔），用于历史 URL 域名替换', '')
            ->addOption('tables', null, InputOption::VALUE_OPTIONAL, '限定扫描的表（不含表前缀，逗号分隔）', '')
            ->addOption('skip-files', null, InputOption::VALUE_NONE, '跳过文件上传阶段')
            ->addOption('skip-records', null, InputOption::VALUE_NONE, '跳过附件记录补建阶段')
            ->addOption('skip-db', null, InputOption::VALUE_NONE, '跳过数据库改写阶段')
            ->addOption('report-orphans', null, InputOption::VALUE_NONE, '只盘点云端孤儿对象（本地无文件且 DB 无引用），不写不删；需驱动支持 listObjects')
            ->addOption('force', null, InputOption::VALUE_NONE, '对象已存在时也覆盖上传');
    }

    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('本地文件全量迁移至云存储');

        $this->dryRun   = (bool)$input->getOption('dry-run');
        $this->force    = (bool)$input->getOption('force');
        $this->dir      = trim((string)$input->getOption('dir'), '/') ?: 'upload';
        $this->platform = strtolower(trim((string)$input->getOption('platform')));

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

        $io->text(sprintf('存储驱动: <info>%s</info>   对象根目录: <info>%s</info>', $this->platform, $this->dirname));
        $io->text(sprintf('访问域名: <info>%s</info>%s', $this->domain, $this->bucket !== '' ? '   存储桶: <info>' . $this->bucket . '</info>' : ''));
        $io->text(sprintf('运行模式: %s', $this->dryRun ? '<comment>dry-run（不写入）</comment>' : '<info>实际执行</info>'));
        $io->text(sprintf('白名单主机: %s', $this->hosts ? implode(', ', $this->hosts) : sprintf('（无；仅处理 /%s/ 绝对路径）', $this->dir)));

        try {
            if ($input->getOption('report-orphans')) {
                return $this->reportOrphans($io);
            }
            if (!$input->getOption('skip-files')) {
                $this->migrateFiles($io);
            }
            if (!$input->getOption('skip-records')) {
                $this->syncUploadRecords($io);
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
     * 解析存储驱动与访问配置（驱动无关）
     *
     * 驱动由 --platform 指定，未指定则取「上传配置」的 mode；实际读写能力全部来自
     * UploadFile::disk()，因此本命令无需感知任何云厂商 SDK 或鉴权字段。
     *
     * @throws \Exception
     */
    private function bootstrap(): void
    {
        if ($this->platform === '') {
            $this->platform = strtolower(trim((string)(UploadFile::config('upload', [])['mode'] ?? '')));
        }
        if ($this->platform === '') {
            throw new \Exception('未能确定存储驱动，请检查后台「系统配置 - 上传设置」的 mode，或用 --platform 指定');
        }
        if ($this->platform === 'local') {
            throw new \Exception('当前为本地存储（local），没有云端可迁移；请先把上传配置切换到云存储驱动');
        }

        try {
            $this->disk = UploadFile::disk($this->platform, false);
        } catch (\Throwable $e) {
            throw new \Exception(sprintf('存储驱动「%s」不可用: %s', $this->platform, $e->getMessage()));
        }

        $config       = UploadFile::config($this->platform, []);
        $this->bucket = (string)($config['bucket'] ?? '');
        $this->domain = rtrim((string)($config['domain'] ?? ''), '/');
        if ($this->domain === '') {
            throw new \Exception(sprintf('存储驱动「%s」缺少 domain（访问域名），无法生成资源地址与改写历史 URL', $this->platform));
        }

        // 对象根目录以「配置」为准；--dir 只决定本地扫描目录，两者不一致时以配置为准
        $this->dirname     = trim((string)($config['dirname'] ?? $this->dir), '/') ?: $this->dir;
        $this->tablePrefix = (string)Db::connection()->getTablePrefix();
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
            Log::warning('upload:migrate-storage 解析历史主机失败: ' . $e->getMessage());
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
        $io->section('阶段 1/3：本地文件 → 云存储');

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

        foreach ($files as $file) {
            $this->fileStats['discovered']++;
            $this->fileStats['bytes'] += $file['size'];

            if (!$this->force && $this->objectExists($file['key'])) {
                $this->fileStats['skipped']++;
                continue;
            }

            try {
                // 统一走驱动上传（object_key 指定精确 key）：历史文件的 key 不能改名，
                // 否则数据库里的历史引用会全部失效；零字节文件由驱动自行兼容
                $this->disk->uploadServerFile($file['path'], ['object_key' => $file['key']]);
                if (!$this->verifyUploaded($file['key'])) {
                    throw new \RuntimeException('校验失败：上传后云端未找到该对象');
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

    /**
     * 云端对象是否存在
     *
     * 走驱动的 exists() 能力；驱动未实现时降级为「一律视为不存在」（即直接覆盖上传），
     * 且只提示一次，避免逐文件刷屏。
     */
    private function objectExists(string $key): bool
    {
        if ($this->supportsExists === false) {
            return false;
        }

        try {
            $exists = $this->disk->exists($key);
            $this->supportsExists ??= true;
            return $exists;
        } catch (\Throwable $e) {
            if ($this->supportsExists === null) {
                $this->supportsExists = false;
                Log::warning(sprintf('存储驱动「%s」未实现 exists()，已降级为直接覆盖上传: %s', $this->platform, $e->getMessage()));
            }
            return false;
        }
    }

    /**
     * 上传后校验：云端能取到该对象
     *
     * 驱动不支持 exists() 时不做校验（上传本身失败会由驱动抛异常）。
     */
    private function verifyUploaded(string $key): bool
    {
        return $this->supportsExists === false || $this->objectExists($key);
    }

    // ═══════════════════════ 附件记录阶段 ═══════════════════════

    /**
     * 阶段 2/3：为迁移的历史文件补齐 sys_upload 附件记录
     *
     * 历史文件此前没有附件记录（后台附件列表看不到，插件卸载也无法按 source 精确回收）。
     * 这里按对象 key 反查既有记录，缺失的按当前存储配置补建，字段口径与上传服务
     * （app\service\admin\system\config\UploadService::upload）保持一致。
     *
     * 仅为云端确实存在的对象建记录，避免出现指向不存在对象的记录（如上传失败的条目）。
     */
    private function syncUploadRecords(SymfonyStyle $io): void
    {
        $io->section('阶段 2/3：补齐 sys_upload 附件记录');

        $absDir = public_path() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $this->dir);
        if (!is_dir($absDir)) {
            $io->warning('本地目录不存在，跳过附件记录阶段: ' . $absDir);
            return;
        }

        $files = $this->scanFiles($absDir);
        $io->text(sprintf('待核对文件 <info>%d</info> 个', count($files)));

        $platform = $this->platform;
        $space    = UploadFile::spaceMark($platform);

        foreach ($files as $file) {
            $this->recordStats['scanned']++;

            if (!$this->dryRun && !$this->objectExists($file['key'])) {
                $this->recordStats['skipped']++;
                $this->recordStats['errors'][] = ['key' => $file['key'], 'error' => '云端对象不存在，已跳过建记录'];
                continue;
            }

            if (Db::table('sys_upload')->where('path', $file['key'])->where('platform', $platform)->exists()) {
                $this->recordStats['existing']++;
                continue;
            }

            $relative = substr($file['key'], strlen($this->dirname) + 1);
            $source   = $this->resolveSource($relative);
            $size     = (int)$file['size'];
            $data     = [
                'platform'          => $platform,
                'space'             => $space,
                'source'            => $source,
                'original_filename' => basename($relative),
                'filename'          => basename($relative),
                'hash'              => (string)md5_file($file['path']),
                'content_type'      => (string)(mime_content_type($file['path']) ?: 'application/octet-stream'),
                'base_path'         => '/' . $file['key'],
                'path'              => $file['key'],
                'ext'               => strtolower((string)pathinfo($relative, PATHINFO_EXTENSION)),
                'size'              => $size,
                'size_info'         => formatBytes($size),
                'url'               => $this->domain . '/' . $file['key'],
            ];

            $this->recordStats['by_source'][$source] = ($this->recordStats['by_source'][$source] ?? 0) + 1;
            if (count($this->recordStats['samples']) < 10) {
                $this->recordStats['samples'][] = sprintf('%s  [%s]', $file['key'], $source);
            }

            if ($this->dryRun) {
                $this->recordStats['created']++;
                continue;
            }

            try {
                (new Upload())->fill($data)->save();
                $this->recordStats['created']++;
            } catch (\Throwable $e) {
                $this->recordStats['failed']++;
                $this->recordStats['errors'][] = ['key' => $file['key'], 'error' => $e->getMessage()];
                $io->text(sprintf('<error>✗</error> %s  %s', $file['key'], $e->getMessage()));
            }
        }

        $io->text(sprintf(
            '附件记录阶段完成：新建 %d，已存在 %d，跳过 %d，失败 %d',
            $this->recordStats['created'],
            $this->recordStats['existing'],
            $this->recordStats['skipped'],
            $this->recordStats['failed']
        ));
    }

    /**
     * 按对象 key 的首层目录推断归属来源
     *
     * 与插件上传约定一致：插件以自身编码作为 sub_dir，对象因此落在 {dirname}/{code}/...。
     * 命中 plugin/{code} 目录记为 plugin:{code}（插件卸载按 source 精确回收），否则为系统默认来源。
     */
    private function resolveSource(string $relative): string
    {
        $first = explode('/', trim(str_replace('\\', '/', $relative), '/'))[0] ?? '';
        if ($first !== '' && is_dir(base_path() . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . $first)) {
            return Upload::pluginSource($first);
        }

        return Upload::SOURCE_DEFAULT;
    }

    // ═══════════════════════ 数据库阶段 ═══════════════════════

    private function migrateDatabase(SymfonyStyle $io): void
    {
        $io->section('阶段 3/3：历史 URL → 云存储访问域名');

        $columns = $this->discoverTextColumns($this->dir);
        if (empty($columns)) {
            $io->warning(sprintf('未发现包含 %s/ 的文本列', $this->dir));
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
     * 发现「包含指定目录关键字 且所属表有主键」的文本列
     *
     * @param string $dir 关键字所在目录名：改写阶段传本地目录名（--dir），
     *                    孤儿比对阶段传对象根目录名（dirname），两者可能不同名
     *
     * @return array<int, array{table:string, column:string, pk:string}>
     */
    private function discoverTextColumns(string $dir): array
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

        // 只保留真正存在目录引用的表，避免无谓全表扫描
        $hits   = [];
        $needle = '%' . $dir . '/%';
        foreach ($candidates as $candidate) {
            $sql = sprintf(
                'SELECT COUNT(*) AS n FROM `%s` WHERE `%s` LIKE ?',
                $this->safeIdentifier($candidate['table']),
                $this->safeIdentifier($candidate['column'])
            );
            $count = (int)(Db::select($sql, [$needle])[0]->n ?? 0);
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
            $params = ['%' . $this->dir . '/%'];
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
     * md_sys_upload 专有处理：url / th_url 走改写规则，platform 由 local 改为当前驱动
     */
    private function handleSysUpload(string $pk): void
    {
        $realTable = $this->safeIdentifier($this->tablePrefix . 'sys_upload');
        $pkCol     = $this->safeIdentifier($pk);

        $lastPk = null;
        while (true) {
            $params   = [];
            $sql      = "SELECT `{$pkCol}` AS __pk, `url` AS u, `th_url` AS t, `platform` AS p
                       FROM `{$realTable}` WHERE `url` LIKE ?";
            $params[] = '%' . $this->dir . '/%';
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
                    $update['platform'] = $this->platform;
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
     * 本地目录名（--dir，默认 upload）与对象根目录（存储配置 dirname，如 storage）可能不同名：
     * 历史引用按本地目录名书写，改写后必须落到对象根目录，否则云端取不到对象。
     *
     * 1) 自有主机白名单的绝对/协议相对 URL（域名 + 本地目录）→ CDN + 对象根目录
     * 2) public/{dir}/ → {dirname}/
     * 3) 其余以 /{dir}/ 开头（前一字符不是 [A-Za-z0-9:/]）→ {cdn}/{dirname}/
     */
    private function rewrite(string $value): string
    {
        $localDir = preg_quote($this->dir, '#');
        if ($value === '' || !str_contains($value, $this->dir . '/')) {
            return $value;
        }

        if (!empty($this->hosts)) {
            $hosts = implode('|', array_map(static fn($host) => preg_quote($host, '#'), $this->hosts));
            $value = (string)preg_replace(
                '#(?<![\w.-])(?:https?:)?//(?:' . $hosts . ')/' . $localDir . '/#i',
                $this->domain . '/' . $this->dirname . '/',
                $value
            );
        }

        $value = str_replace('public/' . $this->dir . '/', $this->dirname . '/', $value);

        return (string)preg_replace(
            '#(?<![A-Za-z0-9:/])/' . $localDir . '/#',
            $this->domain . '/' . $this->dirname . '/',
            $value
        );
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
     * 记录「命中本地目录但不能改写」的行，用于排查漏改
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

        $prefix = $this->dirname . '/';
        try {
            $objects = $this->disk->listObjects($prefix);
        } catch (\Throwable $e) {
            $io->warning(sprintf('存储驱动「%s」未实现 listObjects()，无法盘点孤儿对象: %s', $this->platform, $e->getMessage()));
            return self::SUCCESS;
        }
        $objects = array_fill_keys($objects, 0);

        $local  = [];
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
            $orphans[] = $key;
        }

        $file = $this->reportDir . '/orphans-' . date('YmdHis') . '.json';
        file_put_contents($file, json_encode([
            'generated_at' => date('Y-m-d H:i:s'),
            'platform'     => $this->platform,
            'bucket'       => $this->bucket,
            'prefix'       => $prefix,
            'cloud_total'  => count($objects),
            'local_total'  => count($local),
            'db_ref_total' => count($referenced),
            'orphans'      => $orphans,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $io->text(sprintf('云端对象 %d，本地文件 %d，DB 引用 %d', count($objects), count($local), count($referenced)));
        $io->text(sprintf('孤儿对象 <comment>%d</comment> 个（listObjects 仅返回 key，不再统计体积）', count($orphans)));
        $io->note('报告已写入: ' . $file . '（确认后用 upload:clean-prefix 定向清理）');

        return self::SUCCESS;
    }

    /**
     * 汇总数据库中出现的「对象 key」（用于孤儿比对）
     *
     * 比对基准是对象命名空间（dirname，如 storage），不是本地目录名（--dir，如 upload）：
     * 改写完成后库里的引用已是 {cdn}/{dirname}/xxx，用本地目录名去扫会一个都匹配不到，
     * 从而把全部正常对象误判成孤儿。
     */
    private function collectReferencedKeys(): array
    {
        $keys    = [];
        $needle  = '%' . $this->dirname . '/%';
        // 允许前置字符为 / : . 等（URL 场景），只排除紧邻字母数字的误匹配
        $pattern = '#(?<![A-Za-z0-9])' . preg_quote($this->dirname, '#') . '/[^\s"\'<>()\[\],;]+#';

        foreach ($this->discoverTextColumns($this->dirname) as $column) {
            $realTable = $this->safeIdentifier($this->tablePrefix . $column['table']);
            $valCol    = $this->safeIdentifier($column['column']);
            $pkCol     = $this->safeIdentifier($column['pk']);
            $lastPk    = null;

            while (true) {
                $params = [$needle];
                $sql    = "SELECT `{$valCol}` AS __val, `{$pkCol}` AS __pk
                           FROM `{$realTable}` WHERE `{$valCol}` LIKE ?";
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
                    if (!is_string($row->__val)) {
                        continue;
                    }
                    if (preg_match_all($pattern, $row->__val, $matches)) {
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
        $file = $this->reportDir . '/storage-' . date('YmdHis') . '.json';
        file_put_contents($file, json_encode([
            'generated_at' => date('Y-m-d H:i:s'),
            'dry_run'      => $this->dryRun,
            'platform'     => $this->platform,
            'bucket'       => $this->bucket,
            'cdn'          => $this->domain,
            'dirname'      => $this->dirname,
            'hosts'        => $this->hosts,
            'files'        => $this->fileStats,
            'records'      => $this->recordStats,
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
            ['附件记录', '新建', (string)$this->recordStats['created']],
            ['附件记录', '已存在（跳过）', (string)$this->recordStats['existing']],
            ['附件记录', '云端无对象（跳过）', (string)$this->recordStats['skipped']],
            ['附件记录', '失败', (string)$this->recordStats['failed']],
            ['数据库', '命中表 / 列', $this->dbStats['tables'] . ' / ' . $this->dbStats['columns']],
            ['数据库', '扫描行', (string)$this->dbStats['scanned']],
            ['数据库', '改写行', (string)$this->dbStats['changed']],
            ['数据库', '无需改写（含本地目录但不构成资源路径）', (string)$this->dbStats['unchanged']],
        ]);

        if (!empty($this->recordStats['by_source'])) {
            $io->text('待建记录归属来源分布：');
            foreach ($this->recordStats['by_source'] as $source => $count) {
                $io->text(sprintf('  %s：%d', $source, $count));
            }
        }

        if (!empty($this->recordStats['samples'])) {
            $io->text('待建记录样例（对象 key [来源]）：');
            foreach ($this->recordStats['samples'] as $sample) {
                $io->text('  ' . $sample);
            }
        }

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
            $io->success(sprintf('迁移完成。存储驱动为 %s，对象根目录为 %s。', $this->platform, $this->dirname));
        }
    }
}