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

use core\foundation\base\BaseService;
use core\io\upload\UploadFile;
use Qiniu\Auth;
use Qiniu\Storage\BucketManager;
use support\Db;
use support\Log;

/**
 * 上传资源清理服务（按目录前缀回收残留）
 *
 * 插件资源统一落在 {dirname}/{插件code}/ 前缀下（dirname 取自当前存储驱动的配置，默认 upload）。
 * 插件卸载或停用后，本服务负责回收三处残留：
 *
 *   1. 云端对象：{dirname}/{code}/ 前缀下的全部 bucket 对象
 *   2. 本地目录：public/{dirname}/{code}/
 *   3. 附件记录：md_sys_upload 中 base_path / path 命中该前缀的行
 *
 * 安全守卫（任一不满足直接抛异常，绝不删除）：
 *   - code 仅允许 [A-Za-z0-9_-]
 *   - 前缀必须以 / 结尾，且长度 >= strlen(dirname) + 2
 *   - code 不得等于 dirname（防止误删根目录 {dirname}/）
 *   - 本地目录必须位于 public/{dirname}/ 之内
 *   - 云端对象数量超过 limit 且未显式确认时拒绝执行
 *
 * @author Mr.April
 * @since  1.0.0
 */
class CloudResourceCleanerService extends BaseService
{
    /** 单次清理的对象数量上限（超过需显式确认） */
    public const DEFAULT_LIMIT = 10000;

    /** 七牛批量操作单次上限 */
    private const BATCH_SIZE = 1000;

    /** 允许的编码 / 目录名字符集 */
    private const SAFE_PATTERN = '/^[A-Za-z0-9_-]+$/';

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
     * 执行清理（云对象 + 本地目录 + 附件记录）
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
            'dirname'  => $context['dirname'],
            'prefix'   => $context['prefix'],
            'mode'     => $context['mode'],
            'apply'    => $apply,
            'limit'    => $limit,
            'cloud'    => [],
            'local'    => [],
            'database' => [],
        ];

        $report['cloud']    = $this->handleCloud($context, $limit, $confirmed, $apply);
        $report['local']    = $this->handleLocal($context, $apply);
        $report['database'] = $this->handleDatabase($context, $keepDb, $apply);

        // 清理动作必须留痕（预演同样记录，便于审计）
        Log::info($apply ? '上传资源清理完成' : '上传资源清理预演', [
            'code'     => $report['code'],
            'prefix'   => $report['prefix'],
            'cloud'    => $report['cloud'],
            'local'    => $report['local'],
            'database' => $report['database'],
        ]);

        return $report;
    }

    /**
     * 解析并校验清理上下文（前缀、本地目录）
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
            'dirname'  => $dirname,
            'prefix'   => $prefix,
            'mode'     => $mode,
            'localDir' => $localDir,
        ];
    }

    /**
     * 云端对象清理
     */
    private function handleCloud(array $context, int $limit, bool $confirmed, bool $apply): array
    {
        $result = [
            'enabled'    => false,
            'total'      => 0,
            'bytes'      => 0,
            'deleted'    => 0,
            'failed'     => 0,
            'over_limit' => false,
            'sample'     => [],
            'errors'     => [],
        ];

        $manager = $this->qiniuManager();
        if ($manager === null) {
            $result['errors'][] = '七牛配置不完整，跳过云端清理';
            return $result;
        }
        [$bucketManager, $bucket] = $manager;
        $result['enabled'] = true;

        $objects = [];
        $marker  = null;
        do {
            [$ret, $err] = $bucketManager->listFiles($bucket, $context['prefix'], $marker, self::BATCH_SIZE);
            if ($err) {
                $result['errors'][] = '列举云对象失败: ' . json_encode($err, JSON_UNESCAPED_UNICODE);
                return $result;
            }
            foreach ($ret['items'] ?? [] as $item) {
                $objects[(string)$item['key']] = (int)($item['fsize'] ?? 0);
            }
            $marker = $ret['marker'] ?? null;
        } while (!empty($marker));

        $result['total']      = count($objects);
        $result['bytes']      = array_sum($objects);
        $result['over_limit'] = $result['total'] > $limit;
        $result['sample']     = array_slice(array_keys($objects), 0, 20);

        if ($result['total'] === 0) {
            return $result;
        }
        if ($result['over_limit'] && !$confirmed) {
            throw new \Exception(sprintf(
                '云端对象 %d 个，超出上限 %d；确认无误请加 --yes（或调大 --limit）后重试',
                $result['total'],
                $limit
            ));
        }
        if (!$apply) {
            return $result;
        }

        foreach (array_chunk(array_keys($objects), self::BATCH_SIZE) as $chunk) {
            $operations = BucketManager::buildBatchDelete($bucket, $chunk);
            [$ret, $err] = $bucketManager->batch($operations);
            if ($err) {
                $result['failed'] += count($chunk);
                $result['errors'][] = '批量删除失败: ' . json_encode($err, JSON_UNESCAPED_UNICODE);
                continue;
            }
            foreach ((array)$ret as $one) {
                if ((int)($one['code'] ?? 0) === 200) {
                    $result['deleted']++;
                } else {
                    $result['failed']++;
                    $result['errors'][] = (string)($one['key'] ?? '') . ' ' . (string)($one['error'] ?? '删除失败');
                }
            }
        }

        return $result;
    }

    /**
     * 本地目录清理
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

    /**
     * 附件记录清理
     */
    private function handleDatabase(array $context, bool $keepDb, bool $apply): array
    {
        $result = ['matched' => 0, 'deleted' => 0, 'skipped' => $keepDb];

        $like = '%' . $context['prefix'] . '%';
        $query = static fn() => Db::table('sys_upload')->where(function ($q) use ($like) {
            $q->where('base_path', 'like', $like)->orWhere('path', 'like', $like);
        });

        try {
            $result['matched'] = (int)$query()->count();
        } catch (\Throwable $e) {
            Log::warning('上传资源清理统计附件记录失败', ['error' => $e->getMessage()]);
            return $result;
        }

        if (!$apply || $keepDb || $result['matched'] === 0) {
            return $result;
        }

        // 直接走 SQL 硬删除：绕过模型回收站钩子，避免 CLI 环境缺少请求上下文而失败
        $result['deleted'] = (int)$query()->delete();

        return $result;
    }

    /**
     * 构建七牛管理客户端，配置不完整时返回 null
     */
    private function qiniuManager(): ?array
    {
        try {
            $config = UploadFile::config('qiniu', []) ?? [];
        } catch (\Throwable $e) {
            Log::warning('上传资源清理读取七牛配置失败', ['error' => $e->getMessage()]);
            return null;
        }

        if (empty($config['accessKey']) || empty($config['secretKey']) || empty($config['bucket'])) {
            return null;
        }

        return [
            new BucketManager(new Auth((string)$config['accessKey'], (string)$config['secretKey'])),
            (string)$config['bucket'],
        ];
    }
}