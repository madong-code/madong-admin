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
namespace core\io\upload\storage;

use core\foundation\exception\handler\UploadException;
use Qiniu\Auth;
use Qiniu\Storage\BucketManager;
use Qiniu\Storage\UploadManager;

class Qiniu extends BaseUpload
{
    protected ?UploadManager $instance = null;
    protected ?string $uploadToken = null;
    protected ?BucketManager $bucketManager = null;

    public function getInstance(): UploadManager
    {
        return $this->instance ??= new UploadManager();
    }

    public function getBucketManager(): BucketManager
    {
        return $this->bucketManager ??= new BucketManager(new Auth($this->config['accessKey'], $this->config['secretKey']));
    }

    public function getUploadToken(): string
    {
        return $this->uploadToken ??= (new Auth($this->config['accessKey'], $this->config['secretKey']))->uploadToken($this->config['bucket']);
    }

    public function uploadFile(array $options = []): array
    {
        $result = [];
        $domain = rtrim($this->config['domain'], '/');

        foreach ($this->files as $key => $file) {
            $uniqueId = $this->getUniqueId($file->getPathname());
            $saveName = $uniqueId . '.' . $file->getUploadExtension();
            $object   = $this->buildObjectKey($saveName, $options);

            $temp = [
                'key'         => $key,
                'origin_name' => $file->getUploadName(),
                'save_name'   => $saveName,
                'save_path'   => $object,
                'url'         => $domain . $this->dirSeparator . $object,
                'unique_id'   => $uniqueId,
                'size'        => $file->getSize(),
                'mime_type'   => $file->getUploadMimeType(),
                'extension'   => $file->getUploadExtension(),
                'base_path'   => $this->dirSeparator . $object
            ];

            try {
                [$ret, $err] = $this->getInstance()->putFile($this->getUploadToken(), $object, $file->getPathname());
                if ($err) {
                    throw new UploadException((string)$err);
                }
                $result[] = $temp;
            } catch (\Throwable $exception) {
                throw new UploadException($exception->getMessage());
            }
        }

        return $result;
    }

    public function uploadServerFile(string $filePath, array $options = []): array
    {
        $file = new \SplFileInfo($filePath);
        if (!$file->isFile()) {
            throw new UploadException('请检查上传文件是否是一个有效的文件，文件不存在: ' . $filePath);
        }

        $uniqueId = hash_file('sha256', $file->getPathname());
        $object   = $this->buildObjectKey($uniqueId . '.' . $file->getExtension(), $options);

        $result = [
            'origin_name' => $file->getRealPath(),
            'save_path'   => $object,
            'url'         => $this->config['domain'] . $this->dirSeparator . $object,
            'unique_id'   => $uniqueId,
            'size'        => $file->getSize(),
            'mime_type'   => mime_content_type($file->getPathname()) ?: 'application/octet-stream',
            'extension'   => $file->getExtension(),
            'base_path'   => $this->dirSeparator . $object,
        ];

        [$ret, $err] = $this->getInstance()->putFile($this->getUploadToken(), $object, $file->getPathname());
        if ($err) {
            throw new UploadException((string)$err);
        }

        return $result;
    }

    /**
     * 私有空间：签发带签名的临时直链
     *
     * 传入地址非本空间域名时原样返回（外链不做签名）。
     */
    public function signedUrl(string $key, int $ttl = 0): string
    {
        $object = $this->normalizeObjectKey($key);
        if ($object === null) {
            return trim(str_replace('\\', '/', $key));
        }

        if (!$this->isPrivate()) {
            return $this->buildPublicUrl($object);
        }

        $bucket    = (string)($this->config['bucket'] ?? '');
        $domain    = rtrim((string)($this->config['domain'] ?? ''), '/');
        $accessKey = (string)($this->config['accessKey'] ?? '');
        $secretKey = (string)($this->config['secretKey'] ?? '');
        if ($bucket === '' || $domain === '' || $accessKey === '' || $secretKey === '') {
            throw new UploadException('私有空间配置不完整：bucket / domain / accessKey / secretKey 均不能为空');
        }

        $baseUrl = $this->buildPublicUrl($object);
        $expires = $this->resolveDeadline($ttl) - time();

        return (new Auth($accessKey, $secretKey))->privateDownloadUrl($baseUrl, $expires);
    }

    /**
     * 删除云端对象
     *
     * @param string $key 对象 key 或本空间域名下的绝对地址
     *
     * @return bool 对象不存在返回 false
     * @throws UploadException
     */
    public function deleteFile(string $key): bool
    {
        $object = $this->normalizeObjectKey($key);
        if ($object === null || $object === '') {
            throw new UploadException('七牛资源 key 非法，已拒绝删除: ' . $key);
        }

        [$ret, $err] = $this->getBucketManager()->delete($this->config['bucket'], $object);
        if ($err) {
            // 612：文件不存在，按已删除处理
            if ((int)($err->code ?? 0) === 612) {
                return false;
            }
            throw new UploadException((string)$err);
        }

        return true;
    }

    public function uploadBase64(string $base64, string $extension = 'png'): array
    {
        $base64   = explode(',', $base64);
        $uniqueId = date('YmdHis') . uniqid();
        $object   = $this->buildObjectKey($uniqueId . '.' . $extension);

        [$ret, $err] = $this->getInstance()->put($this->getUploadToken(), $object, base64_decode($base64[1]));
        if ($err) {
            throw new UploadException((string)$err);
        }

        $imgLen   = strlen($base64[1]);
        $fileSize = $imgLen - ($imgLen / 8) * 2;

        return [
            'save_path' => $object,
            'url'       => $this->config['domain'] . $this->dirSeparator . $object,
            'unique_id' => $uniqueId,
            'size'      => $fileSize,
            'extension' => $extension,
        ];
    }
}