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

namespace app\service\admin\system\config;

use app\dao\system\config\UploadDao;
use app\model\system\config\Upload;
use core\foundation\base\BaseService;
use core\foundation\exception\handler\AdminException;
use core\io\upload\UploadFile;
use madong\helper\Arr;
use support\Container;
use support\Log;

class UploadService extends BaseService
{

    public function __construct(UploadDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 远程下载图片到本地
     *
     * @param string $url
     * @param string $subDir 子目录（如 image/202609），为空时落在存储根目录
     *
     * @return mixed
     * @throws \Exception
     */
    public function saveNetworkImage(string $url, string $subDir = ''): array
    {
        $data   = file_get_contents($url);
        if ($data === false) {
            throw new AdminException('获取文件资源失败');
        }
        $image_resource = imagecreatefromstring($data);
        if (!$image_resource) {
            throw new AdminException('创建图片资源失败');
        }
        $filename       = basename($url);
        $file_extension = pathinfo($filename, PATHINFO_EXTENSION);
        $full_dir       = runtime_path() . '/resource/';
        if (!is_dir($full_dir)) {
            mkdir($full_dir, 0777, true);
        }
        $save_path    = $full_dir . $filename;
        $content_type = 'image/';
        switch ($file_extension) {
            case 'jpg':
            case 'jpeg':
                $content_type = 'image/jpeg';
                $result       = imagejpeg($image_resource, $save_path);
                break;
            case 'png':
                $content_type = 'image/png';
                $result       = imagepng($image_resource, $save_path);
                break;
            case 'gif':
                $content_type = 'image/gif';
                $result       = imagegif($image_resource, $save_path);
                break;
            default:
                imagedestroy($image_resource);
                throw new AdminException('文件格式错误');
        }
        imagedestroy($image_resource);
        if (!$result) {
            throw new AdminException('文件保存失败');
        }
        $hash = md5_file($save_path);
        $size = filesize($save_path);

        $mode = UploadFile::config('upload')['mode'] ?? 'local';
        // 去重保持作用域：同一 hash + 同一存储平台 + 同一存储空间才复用，
        // 防止切换平台或切换公开/私有空间后返回当前空间访问不到的旧地址
        $space  = UploadFile::spaceMark($mode);
        $source = Upload::SOURCE_DEFAULT;
        $result = $this->dao->get(['hash' => $hash, 'platform' => $mode, 'space' => $space, 'source' => $source]);
        if (!empty($result)) {
            unlink($save_path);
            return $result->toArray();
        }

        /** @var ConfigService $systemConfigService */
        $systemConfigService = Container::get(ConfigService::class);
        $local               = $systemConfigService->dao->get(['code' => 'local']);
        if (empty($local)) {
            throw new AdminException('缺少本地上传配置信息');
        }
        // 交由存储适配器落盘（local/qiniu/oss/cos/s3），不再硬编码本地目录
        $options     = $subDir === '' ? [] : ['sub_dir' => trim($subDir, '/')];
        $upload      = UploadFile::disk(null, false)->uploadServerFile($save_path, $options);
        $relative    = ltrim((string)($upload['base_path'] ?? ('/' . ltrim((string)$upload['save_path'], '/'))), '/');
        $object_name = basename($relative);
        $file_size   = (int)($upload['size'] ?? $size);
        unlink($save_path);

        $info['platform']          = $mode;
        $info['space']             = $space;
        $info['source']            = $source;
        $info['original_filename'] = $filename;
        $info['filename']          = $object_name;
        $info['hash']              = $hash;
        $info['content_type']      = $upload['mime_type'] ?? $content_type;
        $info['base_path']         = '/' . $relative;
        $info['path']              = $relative;
        $info['ext']               = $upload['extension'] ?? $file_extension;
        $info['size']              = $file_size;
        $info['size_info']         = formatBytes($file_size);
        $info['url']               = $relative;
        $result                    = $this->dao->save($info);
        return $result->toArray();
    }

    /**
     * 文件上传
     *
     * @param string $upload
     * @param bool   $isLocal
     *
     * @return mixed
     * @throws \Throwable
     */
    public function upload(string $upload = '', bool $isLocal = false, string $source = Upload::SOURCE_DEFAULT): mixed
    {
        try {
            return $this->transaction(function () use ($upload, $isLocal, $source) {
                /** @var  ConfigService $systemConfigService */
                $baseConfig          = UploadFile::config('upload');//获取上次配置
                if (empty($baseConfig)) {
                    throw new AdminException('缺少上传配置信息');
                }
                $type = Arr::fetchConfigValue($baseConfig, 'mode') ?: 'local';//上次模式默认本地
                if ($isLocal) {
                    $type = 'local';
                }
                $options = [];
                if (!empty($upload)) {
                    $options['sub_dir'] = $upload;
                }
                $result = UploadFile::uploadFile($options);
                $data   = $result[0];
                $hash   = $data['unique_id'];

                $url  = str_replace('\\', '/', $data['url']);
                $path = str_replace('\\', '/', $data['save_path']);

                // 存储空间标识（default=公开 / private=私有）：切换公开、私有空间后
                // 同一份文件在当前空间已重新落盘，必须新建记录，不能复用另一空间的旧地址
                $space = UploadFile::spaceMark($type);

                // 检查文件是否已存在（同一 hash + 同一存储平台 + 同一存储空间 + 同一来源才复用）
                if ($filesInfo = $this->dao->get(['hash' => $hash, 'platform' => $type, 'space' => $space, 'source' => $source])) {
                    return $filesInfo;
                }

                $inData = [
                    'platform'          => $type,
                    'space'             => $space,
                    'source'            => $source,
                    'original_filename' => $data['origin_name'] ?? '',
                    'filename'          => $data['save_name'],
                    'hash'              => $hash,
                    'content_type'      => $data['mime_type'],
                    'base_path'         => $data['base_path'],
                    'ext'               => $data['extension'],
                    'size'              => $data['size'],
                    'size_info'         => formatBytes($data['size']),
                    'url'               => full_url($url),
                    'path'              => $path,
                ];
                return $this->dao->save($inData);
            });
        } catch (\Exception $e) {
            throw new AdminException($e->getMessage());
        }
    }

    /**
     * 删除附件记录并同步清理已落盘的物理资源
     *
     * 顺序保证：先在同一事务内删除数据库记录，事务提交后再按记录所属平台清理
     * 本地文件 / 云端对象。物理资源清理失败只记日志，不回滚记录删除（避免残留
     * 记录指向已不存在的文件）。
     *
     * @param array $ids 附件ID集合
     *
     * @return array 实际删除的记录ID集合
     * @throws \Throwable
     */
    public function removeWithStorage(array $ids): array
    {
        $ids = array_values(array_filter(array_map(static fn($id) => (string)$id, $ids), static fn($id) => $id !== ''));
        if (empty($ids)) {
            throw new AdminException('删除参数不能为空');
        }

        $model      = $this->dao->getModel();
        $primaryKey = $model->getKeyName();
        $records    = $model->newQuery()->whereIn($primaryKey, $ids)->get();

        $deletedIds = [];
        $this->transaction(function () use ($records, $primaryKey, &$deletedIds) {
            foreach ($records as $record) {
                $record->delete();
                $deletedIds[] = (string)$record->{$primaryKey};
            }
        });

        foreach ($records as $record) {
            /** @var Upload $record */
            $this->purgeStorageObject($record);
        }

        return $deletedIds;
    }

    /**
     * 清理单条附件记录对应的物理资源
     *
     * 记录中 path 为对象 key（云存储）或绝对文件路径（本地），base_path 为兜底。
     */
    private function purgeStorageObject(Upload $record): void
    {
        $platform = trim((string)$record->platform);
        $key      = trim((string)($record->path ?: $record->base_path ?: ''));
        if ($platform === '' || $key === '') {
            return;
        }

        try {
            $deleted = UploadFile::disk($platform, false)->deleteFile($key);
            if (!$deleted) {
                Log::warning('附件物理资源不存在或已删除', [
                    'id'       => (string)$record->id,
                    'platform' => $platform,
                    'key'      => $key,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('附件物理资源删除失败', [
                'id'       => (string)$record->id,
                'platform' => $platform,
                'key'      => $key,
                'error'    => $e->getMessage(),
            ]);
        }
    }

}
