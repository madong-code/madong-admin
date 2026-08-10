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

namespace core\io\upload\support;

/**
 * 存储路径解析器
 *
 * 统一本地目录与云 object key 的路径规则
 */
class StoragePathResolver
{
    /**
     * 解析路径段（当前始终返回空字符串）
     */
    public function pathSegment(array $config = []): string
    {
        return '';
    }

    /**
     * 解析业务子目录
     *
     * @param string $bizSub  业务子目录（如 image、202607）
     * @param array  $config  存储配置
     * @param array  $options 上传 options（可含 sub_dir）
     *
     * @return string
     */
    public function resolveSubdir(string $bizSub = '', array $config = [], array $options = []): string
    {
        if ($bizSub === '' && isset($options['sub_dir'])) {
            $bizSub = (string)$options['sub_dir'];
        }
        if ($bizSub === '' && isset($config['sub_dir'])) {
            $bizSub = is_callable($config['sub_dir'])
                ? (string)$config['sub_dir']()
                : (string)$config['sub_dir'];
        }

        return $this->joinPaths($bizSub);
    }

    /**
     * 构建对象存储 key / 相对路径
     *
     * 规则：{dirname}/{biz_sub}/{filename}
     *
     * @param string $dirname  根目录名（如 upload）
     * @param string $filename 文件名
     * @param array  $config   存储配置
     * @param array  $options  上传 options
     *
     * @return string
     */
    public function buildObjectKey(
        string $dirname,
        string $filename,
        array $config = [],
        array $options = []
    ): string {
        $bizSub = '';
        if (isset($options['sub_dir']) && (string)$options['sub_dir'] !== '') {
            $bizSub = (string)$options['sub_dir'];
        } elseif (isset($config['sub_dir']) && $config['sub_dir'] !== '' && $config['sub_dir'] !== null) {
            $bizSub = is_callable($config['sub_dir'])
                ? (string)$config['sub_dir']()
                : (string)$config['sub_dir'];
        }

        return $this->joinPaths($dirname, $bizSub, $filename);
    }

    /**
     * 是否启用路径隔离（当前始终返回 false）
     */
    public function shouldIsolate(array $config = []): bool
    {
        return false;
    }

    /**
     * 拼接路径段，去除空段与重复斜杠
     */
    public function joinPaths(string ...$segments): string
    {
        $parts = [];
        foreach ($segments as $segment) {
            $segment = str_replace('\\', '/', (string)$segment);
            $segment = trim($segment, '/');
            if ($segment === '') {
                continue;
            }
            $parts[] = $segment;
        }

        return implode('/', $parts);
    }
}