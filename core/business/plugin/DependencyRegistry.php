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
 * 插件依赖归属登记表
 *
 * 记录「哪个插件使用了哪个依赖」，用于：
 * 1. 卸载时按引用计数判断依赖是否仍被其它插件使用，避免误删共用依赖
 * 2. 登记表丢失时可从已安装插件的 info.php 重建（自愈）
 *
 * 存储结构（runtime/install/plugin/dependency-usage.json）：
 * [
 *   'composer' => ['vendor/package' => ['plugin-a', 'plugin-b']],
 *   'admin'    => ['lodash' => ['plugin-a']],
 *   'web'      => ['dayjs' => ['plugin-a']],
 * ]
 */
final class DependencyRegistry
{
    /**
     * 登记表文件
     */
    private string $file;

    /**
     * 登记表内容
     */
    private array $data = [];

    public function __construct(?string $file = null)
    {
        $this->file = $file ?: runtime_path('install/plugin/dependency-usage.json');
        $this->load();
    }

    /**
     * 登记依赖归属
     *
     * @param string $plugin   插件编码
     * @param string $type     依赖类型：composer/admin/web
     * @param array  $packages 包名 => 版本约束
     */
    public function register(string $plugin, string $type, array $packages): void
    {
        foreach (array_keys($packages) as $package) {
            $package = (string)$package;
            if ($package === '') {
                continue;
            }
            $owners = $this->data[$type][$package] ?? [];
            if (!in_array($plugin, $owners, true)) {
                $owners[] = $plugin;
            }
            $this->data[$type][$package] = $owners;
        }

        $this->save();
    }

    /**
     * 释放插件的全部依赖归属
     *
     * @param string $plugin 插件编码
     *
     * @return array 已无其它使用者的依赖列表 [['type' => ..., 'package' => ...]]
     */
    public function release(string $plugin): array
    {
        $released = [];

        foreach ($this->data as $type => $packages) {
            foreach ($packages as $package => $owners) {
                if (!in_array($plugin, $owners, true)) {
                    continue;
                }

                $owners = array_values(array_filter($owners, fn($owner) => $owner !== $plugin));

                if (empty($owners)) {
                    unset($this->data[$type][$package]);
                    $released[] = ['type' => $type, 'package' => $package];
                } else {
                    $this->data[$type][$package] = $owners;
                }
            }

            if (empty($this->data[$type])) {
                unset($this->data[$type]);
            }
        }

        $this->save();

        return $released;
    }

    /**
     * 获取依赖的使用者列表
     */
    public function getOwners(string $type, string $package): array
    {
        return $this->data[$type][$package] ?? [];
    }

    /**
     * 判断依赖是否被其它插件使用
     */
    public function isUsedByOthers(string $type, string $package, string $plugin): bool
    {
        $owners = $this->getOwners($type, $package);

        foreach ($owners as $owner) {
            if ($owner !== $plugin) {
                return true;
            }
        }

        return false;
    }

    /**
     * 全量登记表
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * 从已安装插件的 info.php 重建登记表（自愈）
     *
     * @param string $pluginRoot 插件根目录
     *
     * @return int 登记的条目数量
     */
    public function rebuild(string $pluginRoot): int
    {
        $this->data = [];

        if (!is_dir($pluginRoot)) {
            $this->save();
            return 0;
        }

        $dirs = glob($pluginRoot . '/*/config/info.php') ?: [];

        foreach ($dirs as $infoFile) {
            $pluginDir = basename(dirname(dirname($infoFile)));
            // 仅统计已安装插件
            if (!is_file(dirname($infoFile) . '/installed.php')) {
                continue;
            }

            $info = include $infoFile;
            if (!is_array($info)) {
                continue;
            }

            $composerDeps = $info['require']['composer'] ?? [];
            if (!empty($composerDeps)) {
                $this->register($pluginDir, 'composer', $composerDeps);
            }

            foreach (['admin', 'web'] as $endpoint) {
                $packages = array_merge(
                    $info['npm_require'][$endpoint] ?? [],
                    $info['npm_require']['common'] ?? []
                );
                if (!empty($packages)) {
                    $this->register($pluginDir, $endpoint, $packages);
                }
            }
        }

        return $this->count();
    }

    /**
     * 条目数量
     */
    public function count(): int
    {
        $count = 0;
        foreach ($this->data as $packages) {
            $count += count($packages);
        }
        return $count;
    }

    /**
     * 加载登记表
     */
    private function load(): void
    {
        if (!is_file($this->file)) {
            $this->data = [];
            return;
        }

        $content = file_get_contents($this->file);
        $decoded = json_decode($content ?: '', true);

        $this->data = is_array($decoded) ? $decoded : [];

        // 自愈：剔除已被直接删除（未走卸载流程）的插件残留归属，
        // 避免其永久阻塞其它插件卸载时剥离共用依赖。
        $this->pruneStaleOwners();
    }

    /**
     * 剔除已不在「已安装列表」中的插件 owner（自愈）
     *
     * 插件若被直接从磁盘删除而未走卸载流程，其归属记录会残留在登记表中，
     * 导致其它插件卸载时误判「仍被其它插件使用」而保留依赖。
     * 这里在加载后按「是否存在 config/installed.php」过滤 owner。
     */
    private function pruneStaleOwners(): void
    {
        $installed = $this->installedPluginCodes();
        if ($installed === null) {
            return; // 无法定位插件目录时保持原行为
        }

        $changed = false;
        foreach ($this->data as $type => &$packages) {
            foreach ($packages as $package => $owners) {
                $filtered = array_values(array_filter(
                    $owners,
                    fn($owner) => isset($installed[$owner])
                ));

                if ($filtered !== $owners) {
                    if (empty($filtered)) {
                        unset($packages[$package]);
                    } else {
                        $packages[$package] = $filtered;
                    }
                    $changed = true;
                }
            }

            if (empty($packages)) {
                unset($this->data[$type]);
            }
        }
        unset($packages);

        if ($changed) {
            $this->save();
        }
    }

    /**
     * 获取当前已安装插件编码集合（存在 config/installed.php 即视为已安装）
     *
     * @return array<string,bool>|null 无法定位插件目录时返回 null
     */
    private function installedPluginCodes(): ?array
    {
        $root = function_exists('base_path') ? base_path('plugin') : null;
        if (!$root || !is_dir($root)) {
            return null;
        }

        $codes = [];
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $pluginDir) {
            if (is_file($pluginDir . '/config/installed.php')) {
                $codes[basename($pluginDir)] = true;
            }
        }

        return $codes;
    }

    /**
     * 保存登记表
     */
    private function save(): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents(
            $this->file,
            json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }
}
