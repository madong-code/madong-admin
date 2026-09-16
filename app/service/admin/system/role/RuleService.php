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

namespace app\service\admin\system\role;

use app\service\core\metadata\MetadataCollectorService;
use core\foundation\base\BaseService;
use support\Container;

/**
 * 规则扫描服务
 * 使用 MetadataCollectorService 扫描控制器，提供缓存机制
 */
class RuleService extends BaseService
{
    /**
     * 缓存键
     */
    const CACHE_KEY = 'rule_data';
    const CACHE_TTL = 300; // 缓存5分钟

    /**
     * 获取扫描的权限列表（带缓存）
     *
     * @return array
     * @throws \Exception
     */
    public function getPermissions(): array
    {
        return $this->cacheDriver()->remember(
            self::CACHE_KEY,
            function () {
                $collector = Container::get(MetadataCollectorService::class);
                return $collector->collect();
            },
            self::CACHE_TTL
        );
    }

    /**
     * 获取分类树
     * 主程序权限按模块 tags 归类；插件权限追加一层插件名节点（插件名 → 模块）
     *
     * @return array
     * @throws \Exception
     */
    public function getCategories(): array
    {
        $permissions = $this->getPermissions();
        $categoryMap = [];   // 主程序分类：tag => 节点
        $pluginMap   = [];   // 插件分类：plugin key => 节点
        $pluginTags  = [];   // 插件下模块聚合：plugin key => [tag => true]

        foreach ($permissions as $permission) {
            // 确保 tags 是字符串数组
            $tags       = $this->normalizeTags($permission['tags'] ?? ['未分类']);
            $pluginName = $permission['plugin'] ?? null;

            if (is_string($pluginName) && $pluginName !== '') {
                // 插件权限：一级节点为插件名（key 加前缀避免与主程序 tag 撞 id）
                $key = 'plugin|' . $pluginName;
                if (!isset($pluginMap[$key])) {
                    $categoryId       = md5($key);
                    $pluginMap[$key]  = [
                        'id'       => $categoryId,
                        'pid'      => 0,
                        'name'     => $pluginName,
                        'value'    => $categoryId,
                        'label'    => $pluginName,
                        'plugin'   => $pluginName,
                        'children' => [],
                    ];
                    $pluginTags[$key] = [];
                }
                foreach ($tags as $tag) {
                    $pluginTags[$key][$tag] = true;
                }
                continue;
            }

            // 主程序权限：按模块 tag 归类（叶子，无子级）
            foreach ($tags as $tag) {
                if (!isset($categoryMap[$tag])) {
                    $categoryId        = md5($tag);
                    $categoryMap[$tag] = [
                        'id'       => $categoryId,
                        'pid'      => 0,
                        'name'     => $tag,
                        'value'    => $categoryId,
                        'label'    => $tag,
                        'children' => [],
                    ];
                }
            }
        }

        // 插件节点下挂该插件的模块（tag）子节点
        foreach ($pluginTags as $key => $tags) {
            foreach (array_keys($tags) as $tag) {
                $tagId = md5($key . '|' . $tag);
                $pluginMap[$key]['children'][] = [
                    'id'       => $tagId,
                    'pid'      => $pluginMap[$key]['id'],
                    'name'     => $tag,
                    'value'    => $tagId,
                    'label'    => $tag,
                    'children' => [],
                ];
            }
        }

        // 插件分类统一排在主程序分类之后
        return array_values(array_merge($categoryMap, $pluginMap));
    }

    /**
     * 规范化 tags 为字符串数组
     *
     * @param mixed $tags
     *
     * @return array
     */
    private function normalizeTags(mixed $tags): array
    {
        if (!is_array($tags)) {
            $tags = [$tags];
        }
        return array_map(function ($tag) {
            if (is_string($tag)) {
                return $tag;
            }
            if (is_object($tag) && isset($tag->name)) {
                return $tag->name;
            }
            return (string) $tag;
        }, $tags);
    }

    /**
     * 解析权限的 HTTP 方法（注解优先，方法名前缀兜底）
     *
     * @param array $permission
     *
     * @return string
     */
    private function resolveHttpMethod(array $permission): string
    {
        $httpMethod = $permission['httpMethod'] ?? null;
        if (!is_string($httpMethod) || $httpMethod === '') {
            $httpMethod = $this->extractHttpMethod($permission['method'] ?? '');
        }
        return strtoupper($httpMethod);
    }

    /**
     * 根据分类获取接口列表
     *
     * @param string|null $categoryId
     * @param string|null $keyword
     *
     * @return array
     * @throws \Exception
     */
    public function getRoutesByCategory(?string $categoryId = null, ?string $keyword = null): array
    {
        $permissions = $this->getPermissions();
        $routes      = [];

        foreach ($permissions as $permission) {
            // 确保 tags 是字符串数组
            $tags       = $this->normalizeTags($permission['tags'] ?? ['未分类']);
            $httpMethod = $this->resolveHttpMethod($permission);
            $pluginName = $permission['plugin'] ?? null;

            // 过滤分类（主程序分类：md5(tag)；插件分类：md5('plugin|名') 或 md5('plugin|名|模块')）
            if ($categoryId !== null) {
                if (is_string($pluginName) && $pluginName !== '') {
                    $pluginKey = 'plugin|' . $pluginName;
                    $matchIds  = [md5($pluginKey)];
                    foreach ($tags as $tag) {
                        $matchIds[] = md5($pluginKey . '|' . $tag);
                    }
                } else {
                    $matchIds = array_map(fn($tag) => md5($tag), $tags);
                }
                if (!in_array($categoryId, $matchIds)) {
                    continue;
                }
            }

            // 过滤关键词
            if ($keyword !== null && !empty($keyword)) {
                // 构建搜索文本，只使用字符串类型的字段
                $searchParts = [];

                // 添加 tags
                $searchParts[] = implode(' ', $tags);

                // 添加其他字段（确保是字符串）
                $fields = ['summary', 'description', 'controller', 'method', 'code', 'route'];
                foreach ($fields as $field) {
                    if (isset($permission[$field]) && is_string($permission[$field])) {
                        $searchParts[] = $permission[$field];
                    }
                }

                $searchText = implode(' ', $searchParts);

                if (stripos($searchText, $keyword) === false) {
                    continue;
                }
            }

            $routes[] = [
                'id'          => md5($permission['route'] . '_' . $permission['method']),
                'name'        => $permission['summary'] ?? $permission['description'] ?? $permission['code'],
                'method'      => $httpMethod,
                'path'        => $permission['route'],
                'code'        => $permission['code'],
                'controller'  => $permission['controller'],
                'action'      => $permission['method'],
                'description' => $permission['description'] ?? '',
                'tags'        => $tags,
            ];
        }

        return $routes;
    }

    /**
     * 从方法名中提取 HTTP 方法
     *
     * @param string $methodName
     *
     * @return string
     */
    private function extractHttpMethod(string $methodName): string
    {
        $prefixes    = ['get', 'post', 'put', 'delete', 'patch'];
        $lowerMethod = strtolower($methodName);

        foreach ($prefixes as $prefix) {
            if (str_starts_with($lowerMethod, $prefix)) {
                return strtoupper($prefix);
            }
        }

        return 'GET';
    }

    /**
     * 清除缓存
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->cacheDriver()->delete(self::CACHE_KEY);
    }

    /**
     * 手动触发扫描并刷新缓存
     *
     * @return array
     * @throws \Exception
     */
    public function refresh(): array
    {
        $this->clearCache();
        return $this->getPermissions();
    }
}
