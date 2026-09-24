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

namespace app\service\api\upload;

use app\dao\system\config\UploadDao;
use app\model\system\config\Upload;
use core\foundation\base\BaseService;
use core\foundation\exception\handler\AdminException;
use core\io\upload\UploadFile;
use core\io\upload\UploadScene;

/**
 * 上传服务类
 *
 * 支持两种上传场景：
 * - 管理端上传：使用 UploadScene::admin()
 * - 接口端上传：使用 UploadScene::api()（group_code=default）
 */
class UploadService extends BaseService
{

    public function __construct(UploadDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 根据当前请求上下文决议上传场景
     */
    private function getScene(): UploadScene
    {
        return false ? UploadScene::admin() : UploadScene::api();
    }

    /**
     * 图片上传
     *
     * @param string $upload
     * @param bool   $isLocal
     *
     * @return mixed
     * @throws \Throwable
     */
    public function uploadImage(string $upload = '', bool $isLocal = false, string $source = Upload::SOURCE_DEFAULT): mixed
    {
        try {
            return $this->transaction(function () use ($upload, $isLocal, $source) {
                $config = $this->getUploadConfig();
                if ($isLocal) {
                    $config['mode'] = 'local';
                }
                return $this->handleUpload($config, $upload, $source);
            });
        } catch (\Exception $e) {
            throw new AdminException($e->getMessage());
        }
    }

    /**
     * 视频上传
     *
     * @param string $upload
     * @param bool   $isLocal
     *
     * @return mixed
     * @throws \Throwable
     */
    public function uploadVideo(string $upload = '', bool $isLocal = false, string $source = Upload::SOURCE_DEFAULT): mixed
    {
        try {
            return $this->transaction(function () use ($upload, $isLocal, $source) {
                $config = $this->getUploadConfig();
                if ($isLocal) {
                    $config['mode'] = 'local';
                }
                return $this->handleUpload($config, $upload, $source);
            });
        } catch (\Exception $e) {
            throw new AdminException($e->getMessage());
        }
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
    public function uploadFile(string $upload = '', bool $isLocal = false, string $source = Upload::SOURCE_DEFAULT): mixed
    {
        try {
            return $this->transaction(function () use ($upload, $isLocal, $source) {
                $baseConfig = $this->getUploadConfig();
                $config     = [
                    'mode'         => $baseConfig['mode'] ?? 'local',
                    'single_limit' => 50 * 1024 * 1024,
                    'total_limit'  => 50 * 1024 * 1024,
                    'nums'         => 1,
                    'include'      => ['doc', 'docx', 'xls', 'xlsx', 'pdf', 'txt', 'zip', 'rar', '7z'],
                    'exclude'      => $baseConfig['exclude'] ?? ['php', 'js', 'html', 'sh', 'exe'],
                ];
                if ($isLocal) {
                    $config['mode'] = 'local';
                }
                return $this->handleUpload($config, $upload, $source);
            });
        } catch (\Exception $e) {
            throw new AdminException($e->getMessage());
        }
    }

    /**
     * 远程图片拉取
     *
     * @param string $url
     * @param string $subDir 子目录（如插件归属 portal/image/202609），为空时落在存储根目录
     *
     * @return mixed
     * @throws \Exception
     */
    public function fetchImage(string $url, string $subDir = '', string $source = Upload::SOURCE_DEFAULT): array
    {
        $scene = $this->getScene();
        $mode  = UploadFile::config('upload', [], $scene)['mode'] ?? 'local';
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
            case 'webp':
                $content_type = 'image/webp';
                $result       = imagewebp($image_resource, $save_path);
                break;
            default:
                imagedestroy($image_resource);
                throw new AdminException('文件格式错误');
        }
        imagedestroy($image_resource);
        if (!$result) {
            throw new AdminException('文件保存失败');
        }
        $hash   = md5_file($save_path);
        $size   = filesize($save_path);
        // 存储空间标识（default=公开 / private=私有）
        $space = UploadFile::spaceMark($mode, $scene);
        // 去重：同一 hash + 同一存储平台 + 同一存储空间 + 同一来源才复用
        // （跨平台、跨空间、跨来源都不复用，避免插件与系统互相占用对方记录）
        $result = $this->dao->get(['hash' => $hash, 'platform' => $mode, 'space' => $space, 'source' => $source]);
        if (!empty($result)) {
            unlink($save_path);
            return $result->toArray();
        }
        // 交由存储适配器落盘（local/qiniu/oss/cos/s3），不再硬编码本地目录
        $options     = $subDir === '' ? [] : ['sub_dir' => trim($subDir, '/')];
        $upload      = UploadFile::disk(null, false, $scene)->uploadServerFile($save_path, $options);
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
     * Base64图片上传
     *
     * @param string $base64Data
     * @param string $subDir     子目录（如插件归属 portal/image/202609），为空时落在存储根目录
     *
     * @return mixed
     * @throws \Exception
     */
    public function uploadBase64Image(string $base64Data, string $subDir = '', string $source = Upload::SOURCE_DEFAULT): array
    {
        if (str_starts_with($base64Data, 'data:image/')) {
            $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
        }
        $data = base64_decode($base64Data);
        if ($data === false) {
            throw new AdminException('Base64解码失败');
        }
        $image_resource = imagecreatefromstring($data);
        if (!$image_resource) {
            throw new AdminException('创建图片资源失败');
        }
        $file_extension = 'png';
        $content_type   = 'image/png';
        $file_info      = finfo_open(FILEINFO_MIME_TYPE);
        if ($file_info) {
            $mime_type = finfo_buffer($file_info, $data);
            finfo_close($file_info);
            switch ($mime_type) {
                case 'image/jpeg':
                case 'image/jpg':
                    $file_extension = 'jpg';
                    $content_type   = 'image/jpeg';
                    break;
                case 'image/png':
                    $file_extension = 'png';
                    $content_type   = 'image/png';
                    break;
                case 'image/gif':
                    $file_extension = 'gif';
                    $content_type   = 'image/gif';
                    break;
                case 'image/webp':
                    $file_extension = 'webp';
                    $content_type   = 'image/webp';
                    break;
            }
        }
        $full_dir = runtime_path() . '/resource/';
        if (!is_dir($full_dir)) {
            mkdir($full_dir, 0777, true);
        }
        $filename  = 'upload_' . time() . '_' . random_int(1000, 9999) . '.' . $file_extension;
        $save_path = $full_dir . $filename;
        switch ($file_extension) {
            case 'jpg':
            case 'jpeg':
                $result = imagejpeg($image_resource, $save_path, 90);
                break;
            case 'png':
                $result = imagepng($image_resource, $save_path, 9);
                break;
            case 'gif':
                $result = imagegif($image_resource, $save_path);
                break;
            case 'webp':
                $result = imagewebp($image_resource, $save_path, 90);
                break;
            default:
                imagedestroy($image_resource);
                throw new AdminException('文件格式错误');
        }
        imagedestroy($image_resource);
        if (!$result) {
            throw new AdminException('文件保存失败');
        }
        $hash   = md5_file($save_path);
        $size   = filesize($save_path);
        $scene    = $this->getScene();
        $mode     = UploadFile::config('upload', [], $scene)['mode'] ?? 'local';
        // 存储空间标识（default=公开 / private=私有）
        $space = UploadFile::spaceMark($mode, $scene);
        // 去重：同一 hash + 同一存储平台 + 同一存储空间 + 同一来源才复用
        // （跨平台、跨空间、跨来源都不复用，避免插件与系统互相占用对方记录）
        $result = $this->dao->get(['hash' => $hash, 'platform' => $mode, 'space' => $space, 'source' => $source]);
        if (!empty($result)) {
            unlink($save_path);
            return $result->toArray();
        }
        // 交由存储适配器落盘（local/qiniu/oss/cos/s3），不再硬编码本地目录
        $options     = $subDir === '' ? [] : ['sub_dir' => trim($subDir, '/')];
        $upload      = UploadFile::disk(null, false, $scene)->uploadServerFile($save_path, $options);
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
     * 处理文件上传
     *
     * @param array  $config
     * @param string $upload
     *
     * @return mixed
     * @throws \Throwable
     */
    private function handleUpload(array $config, string $upload = '', string $source = Upload::SOURCE_DEFAULT): mixed
    {
        $scene = $this->getScene();

        $options = [];
        if (!empty($upload)) {
            $options['sub_dir'] = $upload;
        }
        // 使用显式 scene 调用，替代默认静态 UploadFile::uploadFile()
        $result = UploadFile::disk(null, true, $scene)->uploadFile($options);
        $data   = $result[0];
        $url    = str_replace('\\', '/', $data['url']);
        $path   = str_replace('\\', '/', $data['save_path']);

        // 存储空间标识（default=公开 / private=私有）：切换公开、私有空间后
        // 同一份文件在当前空间已重新落盘，必须新建记录，不能复用另一空间的旧地址
        $space = UploadFile::spaceMark($config['mode'], $scene);

        // 检查文件是否已存在（按 hash + platform + space + source 去重，
        // 禁止跨平台、跨空间、跨来源复用：插件资源不能占用系统记录，否则卸载会误删）
        if ($filesInfo = $this->dao->get([
            'hash'     => $data['unique_id'],
            'platform' => $config['mode'],
            'space'    => $space,
            'source'   => $source,
        ])) {
            return $filesInfo;
        }

        $inData = [
            'platform'          => $config['mode'],
            'space'             => $space,
            'source'            => $source,
            'original_filename' => $data['origin_name'] ?? '',
            'filename'          => $data['save_name'],
            'hash'              => $data['unique_id'],
            'content_type'      => $data['mime_type'],
            'base_path'         => $data['base_path'],
            'ext'               => $data['extension'],
            'size'              => $data['size'],
            'size_info'         => formatBytes($data['size']),
            'url'               => full_url($url),
            'path'              => $path,
        ];
        return $this->dao->save($inData);
    }

    /**
     * 获取上传配置
     *
     * @return array
     * @throws \Exception
     */
    private function getUploadConfig(): array
    {
        $scene = $this->getScene();
        return UploadFile::config('upload', [
            'mode'         => 'local',
            'single_limit' => 1024 * 1024,
            'total_limit'  => 1024 * 1024,
            'nums'         => 10,
            'exclude'      => ['php', 'ext', 'exe'],
        ], $scene);
    }
}
