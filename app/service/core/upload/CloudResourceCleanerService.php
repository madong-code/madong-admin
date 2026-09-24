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

namespace app\service\core\upload;

use app\model\system\config\Upload;
use core\foundation\base\BaseService;
use core\io\upload\UploadFile;
use support\Db;
use support\Log;

/**
 * 上传资源清理服务（插件卸载 / 手动回收残留）
 *
 * 归属识别以 sys_upload.source 为准：插件上传的资源落库为 plugin:{插件编码}。
 * 清理流程为「记录驱动」——先取出归属该插件的附件记录，再逐条按记录自身的
 * platform 调用驱动 deleteFile() 删除云对象 / 本地文件，最后删除记录。
 * 这样不依赖目录名与 LIKE 匹配，也不受卸载时存储配置变化的影响，且对
 * local / qiniu / oss / cos / s3 全部驱动生效。
 *
 * 存量数据（改造前落库、source 仍为 default）以 base_path / path 的目录前缀
 * {dirname}/{code}/ 作为兜底识别。
 *
 * 安全守卫（任一不满足直接抛异常，绝不删除）：
 *   - code 仅允许 [A-Za-z0-9_-]
 *   - 前缀必须以 / 结尾，且长度 >= strlen(dirname) + 2
 *   - code 不得等于 dirname（防止误删根目录 {dirname}/）
 *   - 本地目录必须位于 public/{dirname}/ 之内
 *   - 命中记录数超过 limit 且未显式确认时拒绝执行
 *
 * @author Mr.April
 * @since  1.0.0
 */
class CloudResourceCleanerService extends BaseService
{
    /** 单次清理的记录 / 对象数量上限（超过需显式确认） */
    public const DEFAULT_LIMIT = 10000;

    /** 允许的编码 / 目录名字符集 */
    private const SAFE_PATTERN = '/^[A-Za-z0-9_-]+$/';

    /** 报表中保留的样例 / 错误条数 */
    private const REPORT_SAMPLE_SIZE = 20;

    /** 删除记录时的分片大小 */
    private const DELETE_CHUNK_SIZE = 500;

    /**
     * 清理预演（只盘点，不删除任何资源）
     *
     * @param string $code    插件编码（同时作为存储子目录名）
     * @param array  $options limit / yes
     *
     * @return array
     * @throws \Throwable
     */
    public function preview(string $code, array $options = []): array
    {
        return $this->inspect($code, $options, false);
    }

    /**
     * 执行清理（云 / 本地对象 + 附件记录）
     *
     * @param string $code    插件编码（同时作为存储子目录名）
     * @param array  $options limit / yes / keep_db
     *
     * @return array
     * @throws \Throwable
     */
    public function clean(string $code, array $options = []): array
    {
        return $this->inspect($code, $options, true);
    }

    /**
     * 盘点 / 清理主流程
     *
     * @throws \Throwable
     */
    private function inspect(string $code, array $options, bool $apply): array
    {
        $context   = $this->resolveContext($code);
        $limit     = max(1, (int)($options['limit'] ?? self::DEFAULT_LIMIT));
        $confirmed = !empty($options['yes']);
        $keepDb    = !empty($options['keep_db']);

        $report = [
            'code'     => $context['code'],
            'source'   => $context['source'],
            'dirname'  => $context['dirname'],
            'prefix'   => $context['prefix'],
            'mode'     => $context['mode'],
            'apply'    => $apply,
            'limit'    => $limit,
            'cloud'    => [],
            'local'    => [],
            'database' => [],
        ];

        // 1. 收集归属记录：source 精确命中 + 路径前缀兜底存量
        $records = $this->collectRecords($context);
        if (count($records) > $limit && !$confirmed) {
            throw new \Exception(sprintf(
                '命中附件记录 %d 条，超出上限 %d；确认无误请加 --yes（或调大 --limit）后重试',
                count($records),
                $limit
            ));
        }

        // 2. 先删物理资源（依赖记录中的 platform / path），再删附件记录
        $report['cloud']    = $this->purgeStorageObjects($records, $apply);
        $report['database'] = $this->purgeRecords($records, $keepDb, $apply);

        // 3. 本地目录兜底：清理未被附件记录引用的孤儿文件
        $report['local'] = $this->handleLocal($context, $apply);

        // 清理动作必须留痕（预演同样记录，便于审计）
        Log::info($apply ? '上传资源清理完成' : '上传资源清理预演', [
            'code'     => $report['code'],
            'source'   => $report['source'],
            'prefix'   => $report['prefix'],
            'cloud'    => $report['cloud'],
            'local'    => $report['local'],
            'database' => $report['database'],
        ]);

        return $report;
    }

    /**
     * 解析并校验清理上下文（来源标识、前缀、本地目录）
     *
     * @throws \Exception
     */
    private function resolveContext(string $code): array
    {
        $code = trim($code);
        if ($code === '' || !preg_match(self::SAFE_PATTERN, $code)) {
            throw new \Exception("非法的插件编码：{$code}（仅允许字母、数字、下划线与短横线）");
        }

        $mode    = 'local';
        $dirname = '';
        try {
            $upload  = UploadFile::config('upload', []) ?? [];
            $mode    = (string)($upload['mode'] ?? '') ?: 'local';
            $adapter = UploadFile::config($mode, []) ?? [];
            $dirname = trim((string)($adapter['dirname'] ?? ''), '/\\');
        } catch (\Throwable $e) {
            Log::warning('上传资源清理读取存储配置失败，回落默认目录', ['error' => $e->getMessage()]);
        }
        if ($dirname === '') {
            $dirname = 'upload';
        }
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $dirname)) {
            throw new \Exception("非法的存储根目录：{$dirname}");
        }

        $prefix = $dirname . '/' . $code . '/';

        // ── 硬性守卫：任一条不满足即拒绝执行 ──
        if (!str_ends_with($prefix, '/')) {
            throw new \Exception("拒绝清理：前缀必须以 / 结尾（{$prefix}）");
        }
        if ($code === $dirname) {
            throw new \Exception("拒绝清理：插件编码不得与存储根目录同名（{$dirname}）");
        }
        if (strlen($prefix) <= strlen($dirname) + 1) {
            throw new \Exception("拒绝清理：前缀过短，疑似根目录（{$prefix}）");
        }

        $baseDir = public_path() . DIRECTORY_SEPARATOR . $dirname;
        $localDir = $baseDir . DIRECTORY_SEPARATOR . $code;
        $normalizedBase  = str_replace('\\', '/', $baseDir);
        $normalizedLocal = str_replace('\\', '/', $localDir);
        if (!str_starts_with($normalizedLocal . '/', rtrim($normalizedBase, '/') . '/')) {
            throw new \Exception("拒绝清理：本地目录越界（{$normalizedLocal}）");
        }

        return [
            'code'     => $code,
            'source'   => Upload::pluginSource($code),
            'dirname'  => $dirname,
            'prefix'   => $prefix,
            'mode'     => $mode,
            'localDir' => $localDir,
        ];
    }

    /**
     * 收集归属该插件的附件记录
     *
     * @return array<int, array{id:string, platform:string, key:string, size:int, precise:bool}>
     */
    private function collectRecords(array $context): array
    {
        try {
            $rows = $this->queryRecords($context, true);
        } catch (\Throwable $e) {
            // source 列尚未迁移时回落到纯前缀匹配，避免清理流程整体失败
            Log::warning('上传资源清理：按 source 查询失败，回落路径前缀匹配', ['error' => $e->getMessage()]);
            $rows = $this->queryRecords($context, false);
        }

        $records = [];
        foreach ($rows as $row) {
            $row = (array)$row;
            $records[] = [
                'id'       => (string)$row['id'],
                'platform' => trim((string)($row['platform'] ?? '')),
                'key'      => trim((string)($row['path'] ?: $row['base_path'] ?: '')),
                'size'     => (int)($row['size'] ?? 0),
                'precise'  => $context['source'] === (string)($row['source'] ?? ''),
            ];
        }

        return $records;
    }

    /**
     * 查询归属记录
     *
     * @param bool $withSource 是否按 source 精确匹配（false 时仅按路径前缀兜底）
     */
    private function queryRecords(array $context, bool $withSource): array
    {
        $like  = '%' . $context['prefix'] . '%';
        $query = Db::table('sys_upload')->select(['id', 'platform', 'path', 'base_path', 'size']);

        if ($withSource) {
            $source = $context['source'];
            $query->addSelect('source')->where(function ($q) use ($source, $like) {
                // 归属精确命中
                $q->where('source', $source)
                    // 存量兜底：改造前的记录 source 仍为 default，只能按路径前缀识别
                    ->orWhere(function ($sub) use ($like) {
                        $sub->where(function ($path) use ($like) {
                            $path->where('base_path', 'like', $like)->orWhere('path', 'like', $like);
                        })->whereIn('source', [Upload::SOURCE_DEFAULT, '']);
                    });
            });
        } else {
            $query->where(function ($q) use ($like) {
                $q->where('base_path', 'like', $like)->orWhere('path', 'like', $like);
            });
        }

        return $query->get()->all();
    }

    /**
     * 逐条清理物理资源（云对象 / 本地文件）
     *
     * 记录驱动：按每条记录自身的 platform 选择驱动，与当前存储配置、目录名无关。
     */
    private function purgeStorageObjects(array $records, bool $apply): array
    {
        $result = [
            'enabled' => true,
            'total'   => count($records),
            'bytes'   => 0,
            'deleted' => 0,
            'failed'  => 0,
            'skipped' => 0,
            'sample'  => [],
            'errors'  => [],
        ];

        /** @var array<string, \core\io\upload\contract\UploadFileInterface> $drivers */
        $drivers = [];
        foreach ($records as $record) {
            $result['bytes'] += $record['size'];
            if (count($result['sample']) < self::REPORT_SAMPLE_SIZE) {
                $result['sample'][] = $record['key'];
            }
            if (!$apply) {
                continue;
            }
            if ($record['platform'] === '' || $record['key'] === '') {
                $result['skipped']++;
                continue;
            }
            try {
                $driver = $drivers[$record['platform']] ??= UploadFile::disk($record['platform'], false);
                $driver->deleteFile($record['key']) ? $result['deleted']++ : $result['skipped']++;
            } catch (\Throwable $e) {
                $result['failed']++;
                if (count($result['errors']) < self::REPORT_SAMPLE_SIZE) {
                    $result['errors'][] = $record['key'] . ' ' . $e->getMessage();
                }
            }
        }

        return $result;
    }

    /**
     * 删除附件记录
     *
     * 直接走 SQL 硬删除：绕过模型回收站钩子，避免 CLI 环境缺少请求上下文而失败。
     */
    private function purgeRecords(array $records, bool $keepDb, bool $apply): array
    {
        $result = [
            'matched'  => count($records),
            'precise'  => 0,
            'fallback' => 0,
            'deleted'  => 0,
            'skipped'  => $keepDb,
        ];

        $ids = [];
        foreach ($records as $record) {
            $ids[] = $record['id'];
            $record['precise'] ? $result['precise']++ : $result['fallback']++;
        }

        if (!$apply || $keepDb || empty($ids)) {
            return $result;
        }

        foreach (array_chunk($ids, self::DELETE_CHUNK_SIZE) as $chunk) {
            $result['deleted'] += (int)Db::table('sys_upload')->whereIn('id', $chunk)->delete();
        }

        return $result;
    }

    /**
     * 本地目录兜底清理（回收未被附件记录引用的孤儿文件）
     */
    private function handleLocal(array $context, bool $apply): array
    {
        $result = [
            'path'    => $context['localDir'],
            'exists'  => false,
            'files'   => 0,
            'bytes'   => 0,
            'deleted' => false,
        ];

        if (!is_dir($context['localDir'])) {
            return $result;
        }
        $result['exists'] = true;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($context['localDir'], \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isFile()) {
                $result['files']++;
                $result['bytes'] += (int)$item->getSize();
            }
        }

        if ($apply) {
            $result['deleted'] = remove_target_directory($context['localDir']);
        }

        return $result;
    }
}
