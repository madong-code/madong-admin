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

namespace app\service\admin\system\menu;

use app\dao\system\menu\MenuDao;
use app\model\system\menu\Menu;
use core\foundation\base\BaseService;
use core\foundation\exception\handler\AdminException;
use app\service\admin\system\role\RoleMenuService;
use madong\helper\Arr;
use madong\helper\Tree;
use support\Container;

/**
 * @method save(array $data)
 * @method selectModel(array $where, array|string $field = '*', int $page = 0, int $limit = 0, string $order = '', array $with = [], bool $search = false, ?array $withoutScopes = null)
 */
class MenuService extends BaseService
{

    public function __construct(MenuDao $dao)
    {
        $this->dao = $dao;
    }

    public function getPermissionTree(): array
    {
        $query = $this->dao->getModel()
            ->where('enabled', 1);

        $menus = $query->orderBy('sort', 'asc')
            ->get()
            ->toArray();

        return $this->buildMenuTree($menus);
    }

    public function getAllAuth(array $menuIds = []): array
    {
        $query = $this->dao->getModel()
            ->where('path', '<>', '')
            ->where('type', '=', 4);
        $allAuthItems = $query->get(['id', 'path', 'methods'])->toArray();

        if (!empty($menuIds)) {
            $allAuthItems = array_filter($allAuthItems, function ($item) use ($menuIds) {
                return in_array($item['id'], $menuIds);
            });
        }

        return $this->formatAuthDataFromItems($allAuthItems);
    }

    private function formatAuthDataFromItems(array $allAuthItems): array
    {
        $allAuth = [];
        foreach ($allAuthItems as $item) {
            $methodArray = explode(',', $item['methods']);

            $pathKey = strtolower(trim(str_replace(' ', '', $item['path'])));

            foreach ($methodArray as $method) {
                $methodKey             = strtolower(trim($method));
                $allAuth[$methodKey][] = $pathKey;
            }
        }

        foreach ($allAuth as &$paths) {
            $paths = array_unique($paths);
        }
        unset($paths);
        return $allAuth;
    }

    public function getAllMenus(array $where = [], $mode = 'menu', $isTree = true): array
    {
        $query = $this->dao->getModel()
            ->where('enabled', 1);
        $allMenus = $query->orderBy('sort', 'asc')->get();

        $menusToProcess = Arr::filterByWhere($allMenus, $where);

        if ($mode == 'code') {
            return array_column($menusToProcess, 'code');
        }
        if (!$isTree) {
            return $menusToProcess;
        }

        return $this->buildMenuTree($menusToProcess);
    }

    protected function buildMenuTree(array $formattedMenus): array
    {
        $tree = new Tree($formattedMenus);
        return $tree->getTree();
    }

    public function update(int|string $id, array $data): Menu
    {
        return $this->transaction(function () use ($id, $data) {
            $model = $this->get($id);
            if (!$model) {
                throw new AdminException("菜单ID:{$id}不存在");
            }

            $model->fill($data);
            $model->save();

            $this->syncPermissionCacheAfterUpdate($id);
            return $model;
        });
    }

    public function batchDelete(array $data = []): array
    {
        return $this->transaction(function () use ($data) {
            $deletedIds = [];

            foreach ($data as $id) {
                /** @var Menu $item */
                $item = $this->get($id);
                if (!$item) {
                    continue;
                }
                $ids        = $item->deleteWithAllChildren();
                $deletedIds = array_merge($deletedIds, $ids);
            }

            if (!empty($deletedIds)) {
                /** @var RoleMenuService $roleMenuService */
                $roleMenuService = Container::make(RoleMenuService::class);
                /** @var RoleMenuModel $roleMenuModel */
                $roleMenuModel = $roleMenuService->getModel();
                $roleMenuModel->whereIn('menu_id', $deletedIds)->delete();
                
                $this->clearUserPermissionCacheByMenus($deletedIds);
            }

            return array_unique($deletedIds);
        });
    }

    private function syncPermissionCacheAfterUpdate(int|string $menuId): void
    {
        try {
            /** @var RoleMenuService $roleMenuService */
            $roleMenuService = Container::make(RoleMenuService::class);
            $roleIds         = $roleMenuService->getColumn(
                ['menu_id' => $menuId],
                'role_id'
            );
            if (empty($roleIds)) {
                return;
            }

            foreach ($roleIds as $roleId) {
                $adminRoleService = Container::make(\app\service\admin\system\AdminRoleService::class);
                
                $userIds = \app\model\system\AdminRole::where('role_id', $roleId)
                    ->pluck('admin_id')
                    ->toArray();
                
                if (!empty($userIds)) {
                    $currentUser = Container::make(\app\adminapi\CurrentUser::class);
                    foreach ($userIds as $userId) {
                        $currentUser->clearCache($userId);
                    }
                }
            }
        } catch (\Throwable $e) {
            throw new AdminException($e->getMessage());
        }
    }
    
    private function clearUserPermissionCacheByMenus(array $menuIds): void
    {
        try {
            if (empty($menuIds)) {
                return;
            }
            
            /** @var RoleMenuService $roleMenuService */
            $roleMenuService = Container::make(RoleMenuService::class);
            /** @var RoleMenuModel $roleMenuModel */
            $roleMenuModel = $roleMenuService->getModel();
            
            $roleIds = $roleMenuModel->whereIn('menu_id', $menuIds)
                ->pluck('role_id')
                ->unique()
                ->toArray();
            
            if (!empty($roleIds)) {
                $userIds = \app\model\system\AdminRole::whereIn('role_id', $roleIds)
                    ->pluck('admin_id')
                    ->unique()
                    ->toArray();
                
                if (!empty($userIds)) {
                    $currentUser = Container::make(\app\adminapi\CurrentUser::class);
                    foreach ($userIds as $userId) {
                        $currentUser->clearCache($userId);
                    }
                }
            }
        } catch (\Throwable $e) {
            \core\logger\Logger::error("清理菜单相关用户权限缓存失败: " . $e->getMessage());
        }
    }
}
