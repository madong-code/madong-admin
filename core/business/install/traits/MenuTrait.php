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
 * Official Website: https://madong.tech
 */

namespace core\business\install\traits;

use app\model\system\menu\Menu as SysMenu;
use app\model\web\Menu as WebMenu;
use core\io\uuid\Snowflake;

/**
 * 菜单导入 Trait
 */
trait MenuTrait
{

    /**
     * 运行菜单种子
     */
    public function runMenu(): void
    {
        $this->runWebMenu();
        $this->runAdminMenu();
    }

    /**
     * admin 菜单直接写入 sys_menu
     * 加载 admin.php（通用菜单） + admin_standalone.php
     */
    public function runAdminMenu(): void
    {
        $menuTable = $this->table((new SysMenu())->getTable());
        $this->getPdo()->exec("TRUNCATE TABLE `{$menuTable}`");

        $this->loadAdminMenuFile('resource/data/menu/admin.php', $menuTable);
        $this->loadAdminMenuFile('resource/data/menu/admin_standalone.php', $menuTable);
    }

    /**
     * 加载单个 admin 菜单文件到 sys_menu
     */
    private function loadAdminMenuFile(string $relativePath, string $menuTable): void
    {
        $menuFile = base_path($relativePath);
        if (!file_exists($menuFile)) {
            return;
        }

        $menus = require $menuFile;

        foreach ($menus as $menu) {
            $this->insertAdminMenu($menuTable, $menu, 0);
        }
    }

    /**
     * 递归插入 admin 菜单到 sys_menu
     */
    private function insertAdminMenu(string $tableName, array $menu, int|string $pid): int|string
    {
        $id = Snowflake::generate();

        $data = [
            'id' => $id,
            'pid' => $pid,
            'app' => $menu['app'] ?? 'admin',
            'source' => $menu['source'] ?? 'system',
            'title' => $menu['title'] ?? '',
            'code' => $menu['code'] ?? '',
            'level' => $this->getMenuLevel($pid),
            'type' => $menu['type'] ?? 1,
            'sort' => $menu['sort'] ?? 999,
            'path' => $menu['path'] ?? '',
            'component' => $menu['component'] ?? '',
            'redirect' => $menu['redirect'] ?? '',
            'icon' => $menu['icon'] ?? '',
            'is_show' => $menu['is_show'] ?? 1,
            'is_link' => $menu['is_link'] ?? 0,
            'link_url' => $menu['link_url'] ?? '',
            'enabled' => $menu['enabled'] ?? 1,
            'open_type' => $menu['open_type'] ?? 0,
            'is_cache' => $menu['is_cache'] ?? 0,
            'is_sync' => $menu['is_sync'] ?? 1,
            'is_affix' => $menu['is_affix'] ?? 0,
            'is_global' => $menu['is_global'] ?? 0,
            'variable' => $menu['variable'] ?? '',
            'methods' => strtolower($menu['methods'] ?? 'get'),
            'is_frame' => $menu['is_frame'] ?? 1,
            'created_at' => $this->currentTime,
            'created_by' => 0,
            'updated_at' => $this->currentTime,
            'updated_by' => 0,
        ];

        $this->insert($tableName, $data);

        if (!empty($menu['children'])) {
            foreach ($menu['children'] as $child) {
                $this->insertAdminMenu($tableName, $child, $id);
            }
        }
        return $id;
    }

    /**
     * 获取菜单层级（基于 sys_menu 已有记录计算）
     */
    private function getMenuLevel(int|string $pid): int
    {
        if ($pid == 0) return 1;
        $menuTable = $this->table((new SysMenu())->getTable());
        $stmt = $this->getPdo()->prepare("SELECT `level` FROM `{$menuTable}` WHERE id = ?");
        $stmt->execute([$pid]);
        $parent = $stmt->fetch();
        return ($parent['level'] ?? 1) + 1;
    }

    /**
     * 运行Web端菜单种子，直接写入 web_menu
     */
    public function runWebMenu(): void
    {
        $menuFile = base_path('resource/data/menu/web.php');
        if (!file_exists($menuFile)) {
            return;
        }

        $menus = require $menuFile;

        $menuTable = $this->table((new WebMenu())->getTable());
        $this->getPdo()->exec("TRUNCATE TABLE `{$menuTable}`");

        foreach ($menus as $menu) {
            $this->insertWebMenu($menuTable, $menu, 0);
        }
    }

    /**
     * 递归插入Web端菜单到 web_menu
     */
    private function insertWebMenu(string $tableName, array $menu, int|string $pid): int|string
    {
        $id = (int)Snowflake::generate();
        
        $data = [
            'id' => $id, 
            'pid' => $pid,
            'app' => $menu['app'] ?? 'web',
            'category' => $menu['category'] ?? 1,
            'source' => $menu['source'] ?? 'system',
            'code' => $menu['code'] ?? '',
            'name' => $menu['name'] ?? '',
            'url' => $menu['url'] ?? '',
            'icon' => $menu['icon'] ?? '',
            'level' => $menu['level'] ?? 1,
            'type' => $menu['type'] ?? 1,
            'sort' => $menu['sort'] ?? 999,
            'target' => $menu['target'] ?? 1,
            'is_show' => $menu['is_show'] ?? 1,
            'enabled' => $menu['enabled'] ?? 1,
            'created_at' => $this->currentTime,
            'updated_at' => $this->currentTime,
            'deleted_at' => null,
        ];

        $this->insert($tableName, $data);

        if (!empty($menu['children'])) {
            foreach ($menu['children'] as $child) {
                $this->insertWebMenu($tableName, $child, $id);
            }
        }
        
        return $id;
    }
}