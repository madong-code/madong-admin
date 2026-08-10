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

namespace app\service\admin\system\admin;

use app\adminapi\CurrentUser;
use app\dao\system\admin\AdminDao;
use app\model\system\role\RoleMenu;
use core\foundation\base\BaseService;
use Illuminate\Database\Eloquent\Collection;
use app\service\admin\system\menu\MenuService;
use support\Container;

class AuthService extends BaseService
{

    public function __construct(AdminDao $dao)
    {
        $this->dao = $dao;
    }

    public function getMenusByUserRoles(CurrentUser $currentUser, bool $includeButtons = false): ?Collection
    {
        $adminModel   = $currentUser->admin();
        if (!$adminModel) {
            return new Collection();
        }
        
        $isSuperAdmin = boolval($adminModel->getAttribute('is_super'));
        /** @var MenuService $menuService */
        $menuService = Container::make(MenuService::class);
        $map1        = $includeButtons ? ['type' => [1, 2, 3, 4]] : ['type' => [1, 2]];
        
        if ($isSuperAdmin) {
            return $menuService->selectList($map1, '*', 0, 0, 'sort', [], true);
        }
        
        $menuIds = $this->getUserMenuIds($adminModel);
        return $this->getMenusByIds($menuService, array_unique($menuIds), $includeButtons);
    }
    
    private function getUserMenuIds($adminModel): array
    {
        $roleIds = $adminModel->roles()->pluck('sys_role.id')->toArray();
        if (empty($roleIds)) {
            return [];
        }
        
        $connectionName = $adminModel->getConnectionName();
        return RoleMenu::on($connectionName)
            ->whereIn('role_id', $roleIds)
            ->pluck('menu_id')
            ->unique()
            ->toArray();
    }

    private function getMenusByIds(MenuService $menuService, array $ids, bool $includeButtons = false): Collection
    {
        if (empty($ids)) {
            return new Collection();
        }
        
        $menuModel = $menuService->dao->getModel();
        
        $chunkSize  = 200;
        $chunks     = array_chunk($ids, $chunkSize);
        $typeFilter = function ($query) use ($includeButtons) {
            $types = $includeButtons ? [1, 2, 3, 4] : [1, 2];
            $query->whereIn('type', $types);
        };

        $allResults = new Collection();
        foreach ($chunks as $chunk) {
            $results    = (clone $menuModel)
                ->whereIn('id', $chunk)
                ->where('enabled', 1)
                ->where($typeFilter)
                ->orderBy('sort')
                ->get();
            $allResults = $allResults->merge($results);
        }
        return $allResults;
    }

    public function getCodesByUserRoles(CurrentUser $currentUser): array
    {
        $adminModel   = $currentUser->admin();
        if (!$adminModel) {
            return [];
        }
        
        $isSuperAdmin = boolval($adminModel->getAttribute('is_super'));
        if ($isSuperAdmin) {
            return ['*'];
        }

        return $currentUser->getPermissions();
    }
}
