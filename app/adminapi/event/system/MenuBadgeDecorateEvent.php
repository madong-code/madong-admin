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
namespace app\adminapi\event\system;

use core\foundation\base\BaseEvent;

/**
 * 菜单徽标装饰事件
 *
 * 用途：菜单格式化完成后，下游监听器可通过此事件为指定菜单追加徽标
 * 优势：解耦菜单结构与业务徽标逻辑
 *
 * 数据输出规范：snake_case 小写下划线，与前端约定一致
 *   - badge          徽标文本（空字符串=清除/圆点模式）
 *   - badge_type     徽标类型 (normal|dot)
 *   - badge_variants 徽标颜色 (primary|success|warning|info|danger|destructive)
 *
 * 使用示例（在监听器中）：
 *   $event->setBadgeByPath('/order/list', '12', 'destructive');
 *   $event->setDotByPath('/order/settings', 'primary');
 *   $event->setBadgeWithParents('/order/list', '5', 'warning');
 */
class MenuBadgeDecorateEvent extends BaseEvent
{
    /**
     * 已格式化的菜单树（引用修改）
     * @var array
     */
    public array $menus;

    /**
     * 当前用户ID
     * @var int|string
     */
    public int|string $userId;

    /**
     * 客户端类型 (admin/api)
     * @var string
     */
    public string $clientType;

    /**
     * @param array     $menus  已格式化的菜单树（引用传递，监听器可直接修改）
     * @param int|string $userId 当前用户ID
     * @param string    $clientType 客户端类型
     */
    public function __construct(array &$menus, int|string $userId, string $clientType = 'admin')
    {
        $this->menus      = &$menus;
        $this->userId     = $userId;
        $this->clientType = $clientType;
    }

    public function getEventName(): string
    {
        return 'adminapi.menu.badge_decorate';
    }

    /**
     * 为指定 path 的菜单追加徽标
     *
     * @param string $path    菜单路径
     * @param string $badge   徽标文本（如数字、标签）
     * @param string $variant 徽标颜色 (primary|success|warning|info|danger)
     */
    public function setBadgeByPath(string $path, string $badge, string $variant = 'primary'): void
    {
        $this->walkAndSetBadge($this->menus, $path, $badge, $variant, 'normal');
    }

    /**
     * 为指定 path 的菜单追加小圆点徽标
     *
     * @param string $path    菜单路径
     * @param string $variant 徽标颜色
     */
    public function setDotByPath(string $path, string $variant = 'primary'): void
    {
        $this->walkAndSetBadge($this->menus, $path, '', $variant, 'dot');
    }

    /**
     * 为指定菜单及其所有父级追加徽标
     * 子菜单显示数字徽标，父级显示小圆点
     *
     * @param string $path    菜单路径
     * @param string $badge   徽标文本
     * @param string $variant 徽标颜色
     */
    public function setBadgeWithParents(string $path, string $badge, string $variant = 'primary'): void
    {
        $this->walkAndSetBadgeWithParents($this->menus, $path, $badge, $variant);
    }

    /**
     * 递归遍历菜单树，为匹配路径的菜单项设置徽标
     * 输出字段使用 snake_case：badge, badge_type, badge_variants
     *
     * @param array  $menus   菜单树
     * @param string $path    目标路径
     * @param string $badge   徽标文本
     * @param string $variant 徽标颜色
     * @param string $type    徽标类型 (normal|dot)
     */
    private function walkAndSetBadge(array &$menus, string $path, string $badge, string $variant, string $type): void
    {
        foreach ($menus as &$menu) {
            if (($menu['path'] ?? '') === $path) {
                $menu['badge']          = $badge;
                $menu['badge_type']     = $type;
                $menu['badge_variants'] = $variant;
                return;
            }
            if (!empty($menu['children'])) {
                $this->walkAndSetBadge($menu['children'], $path, $badge, $variant, $type);
            }
        }
        unset($menu);
    }

    /**
     * 递归遍历菜单树，为指定路径设置徽标并向上传播到父级
     * 输出字段使用 snake_case：badge, badge_type, badge_variants
     *
     * @param array  $menus   菜单树
     * @param string $path    目标路径
     * @param string $badge   徽标文本
     * @param string $variant 徽标颜色
     * @return bool 是否找到并设置
     */
    private function walkAndSetBadgeWithParents(array &$menus, string $path, string $badge, string $variant): bool
    {
        foreach ($menus as &$menu) {
            if (($menu['path'] ?? '') === $path) {
                $menu['badge']          = $badge;
                $menu['badge_type']     = 'normal';
                $menu['badge_variants'] = $variant;
                return true;
            }
            if (!empty($menu['children'])) {
                if ($this->walkAndSetBadgeWithParents($menu['children'], $path, $badge, $variant)) {
                    // 子菜单已设置徽标，父级追加圆点徽标
                    $menu['badge']          = '';
                    $menu['badge_type']     = 'dot';
                    $menu['badge_variants'] = 'primary';
                    return true;
                }
            }
        }
        unset($menu);
        return false;
    }
}
