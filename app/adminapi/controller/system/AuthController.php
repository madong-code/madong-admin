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

namespace app\adminapi\controller\system;

use app\adminapi\controller\Crud;
use app\adminapi\CurrentUser;
use app\adminapi\event\system\MenuBadgeDecorateEvent;
use app\adminapi\event\system\MenuFormattingEvent;
use app\adminapi\middleware\AccessTokenMiddleware;
use app\adminapi\middleware\OperationMiddleware;
use core\security\jwt\ex\JwtRefreshTokenExpiredException;
use support\Container;
use app\adminapi\middleware\PermissionMiddleware;
use app\adminapi\validate\system\admin\AuthValidate;
use app\service\admin\system\admin\AdminRoleService;
use app\service\admin\system\admin\AdminService;
use app\service\admin\system\admin\AuthService;
use app\service\admin\system\menu\MenuService;
use app\service\admin\system\role\RoleMenuService;
use app\service\admin\system\role\RoleScopeDeptService;
use app\service\admin\system\role\RoleService;
use madong\swagger\attribute\AllowAnonymous;
use madong\swagger\attribute\Permission;
use core\foundation\exception\handler\UnauthorizedHttpException;
use core\security\jwt\JwtToken;
use core\foundation\tool\Json;
use madong\helper\Dict;
use madong\swagger\annotation\response\SimpleResponse;
use OpenApi\Attributes as OA;
use support\Request;
use support\annotation\Middleware;
use Webman\Event\Event;

#[Middleware(AccessTokenMiddleware::class, PermissionMiddleware::class, OperationMiddleware::class)]
final class AuthController extends Crud
{
    private AdminService $adminService;

    public function __construct(AuthService $service, AuthValidate $validate, AdminService $adminService)
    {
        $this->service      = $service;
        $this->validate     = $validate;
        $this->adminService = $adminService;
    }

    #[OA\Get(
        path: '/system/auth/user-info',
        summary: '获取登录用户',
        security: [['Bearer' => [], 'ApiKey' => []]],
        tags: ['权限相关'],
    )]
    #[Permission(code: 'system:auth:user_info')]
    #[AllowAnonymous(requireToken: true, requirePermission: false)]
    #[SimpleResponse(example: '{"code": 0,"msg": "ok","data": {"id": "1","user_name": "admin","real_name": "超级管理员","nick_name": "超级管理员","is_super": 1,"mobile_phone": null,"email": null,"avatar": null,"signed": null,"dashboard": null,"dept_id": "","enabled": 1,"login_ip": "127.0.0.1","login_time": 1762429533,"created_by": null,"updated_by": null,"created_at": "2025-10-30T12:24:09.000000Z","updated_at": "2025-11-06T11:45:34.000000Z","deleted_at": null,"sex": 0,"remark": null,"birthday": null,"tel": null,"is_locked": 0,"role_id_list": [],"post_id_list": [],"created_date": "2025-10-30 20:24:09","updated_date": "2025-11-06 19:45:34","depts": [],"posts": [],"casbin": [],"roles": []}}')]
    public function getUserInfo(Request $request): \support\Response
    {
        try {
            /** @var CurrentUser $currentUser */
            $currentUser = Container::make(CurrentUser::class);

            $data = $currentUser->admin(true);

            if (empty($data)) {
                throw new UnauthorizedHttpException('用户凭证失效请重新登录');
            }

            $data['is_platform_super'] = (int)$currentUser->isPlatformSuper();

            return Json::success('ok', $data);
        } catch (\Throwable $e) {
            return Json::fail($e->getMessage(), null, 401);
        }
    }

    #[OA\Get(
        path: '/system/auth/user-menus',
        summary: '获取用户-权限菜单',
        security: [['Bearer' => [], 'ApiKey' => []]],
        tags: ['权限相关'],
    )]
    #[Permission(code: 'system:auth:user_menus')]
    #[AllowAnonymous(requireToken: true, requirePermission: false)]
    #[SimpleResponse(schema: [], example: [])]
    public function getPermissionsMenu(Request $request): \support\Response
    {
        try {
            $format = input('format', 'vben');
            /** @var CurrentUser $currUser */
            $currUser   = Container::make(CurrentUser::class);
            $collection = $this->service->getMenusByUserRoles($currUser, true);
            $event      = new MenuFormattingEvent($collection, $format);
            $data       = $event->dispatch();

            // 触发徽标装饰事件：供下游监听器追加业务徽标
            $badgeEvent = new MenuBadgeDecorateEvent($data, $currUser->id(), 'admin');
            $badgeEvent->dispatch();

            return Json::success('ok', $badgeEvent->menus);
        } catch (\Throwable $e) {
            return Json::fail($e->getMessage(), [], $e->getCode());
        }
    }

    #[OA\Get(
        path: '/system/auth/user-permissions',
        summary: '权限菜单-包含所有 菜单 按钮  接口',
        security: [['Bearer' => [], 'ApiKey' => []]],
        tags: ['权限相关'],
    )]
    #[Permission(code: 'system:auth:user_permissions')]
    #[SimpleResponse(example: '{"code": 0,"msg": "ok","data": []}')]
    public function getPermissions(Request $request): \support\Response
    {
        try {
            $format = input('format', 'default');
            /** @var CurrentUser $currUser */
            $currUser   = Container::make(CurrentUser::class);
            $collection = $this->service->getMenusByUserRoles($currUser, true);
            $event      = new MenuFormattingEvent($collection, $format);
            $data       = $event->dispatch();
            return Json::success('ok', $data);
        } catch (\Throwable $e) {
            return Json::fail($e->getMessage(), [], $e->getCode());
        }
    }

    #[OA\Get(
        path: '/system/auth/perm-code',
        summary: '获取用户-权限码',
        security: [['Bearer' => [], 'ApiKey' => []]],
        tags: ['权限相关'],
    )]
    #[Permission(code: 'system:auth:perm_code')]
    #[AllowAnonymous(requireToken: true, requirePermission: false)]
    #[SimpleResponse(example: '{"code": 0,"msg": "ok","data": ["admin"]}')]
    public function getUserCodes(Request $request): \support\Response
    {
        try {
            /** @var CurrentUser $currUser */
            $currUser = Container::make(CurrentUser::class);
            $data     = $this->service->getCodesByUserRoles($currUser);
            return Json::success('ok', $data);
        } catch (\Throwable $e) {
            return Json::fail($e->getMessage(), [], $e->getCode());
        }
    }

    #[OA\Get(
        path: '/system/auth/role-menu-ids',
        summary: '通过角色ID获取权限ID集合',
        security: [['Bearer' => [], 'ApiKey' => []]],
        tags: ['权限相关'],
    )]
    #[Permission(code: 'system:auth:role_menu_ids')]
    #[SimpleResponse(example: ['code' => 0, 'message' => 'success', 'data' => []])]
    public function roleMenuIds(Request $request): \support\Response
    {
        try {
            $roleId = $request->input('role_id');
            /** @var  RoleService $systemRoleService */
            $systemRoleService = Container::make(RoleService::class);
            $data              = $systemRoleService->getPermissionColumns($roleId);
            return Json::success('ok', $data);
        } catch (\Throwable $e) {
            return Json::fail($e->getMessage(), [], $e->getCode());
        }
    }

    #[OA\Get(
        path: '/system/auth/role-scope-ids',
        summary: '通过角色ID获取权限范围自定义部门ID集合',
        security: [['Bearer' => [], 'ApiKey' => []]],
        tags: ['权限相关'],
    )]
    #[Permission(code: 'system:auth:role_scope_ids')]
    #[SimpleResponse(example: ['code' => 0, 'message' => 'success', 'data' => []])]
    public function roleScopeIds(Request $request): \support\Response
    {
        try {
            $roleId = $request->input('role_id');
            /** @var  RoleScopeDeptService $systemRoleMenuService */
            $systemRoleMenuService = Container::make(RoleScopeDeptService::class);
            $data                  = $systemRoleMenuService->getColumn(['role_id' => $roleId], 'dept_id');
            return Json::success('ok', $data);
        } catch (\Throwable $e) {
            return Json::fail($e->getMessage(), [], $e->getCode());
        }

    }

    #[OA\Post(
        path: '/system/auth/save-role-menu',
        summary: '保存角色菜单关系',
        security: [['Bearer' => [], 'ApiKey' => []]],
        tags: ['权限相关'],
    )]
    #[Permission(code: 'system:auth:role_menu')]
    #[SimpleResponse(schema: [], example: [])]
    public function saveRoleMenuRelation(Request $request): \support\Response
    {
        try {
            $data = $request->all();
            /** @var RoleMenuService $systemRoleMenuService */
            $systemRoleMenuService = Container::make(RoleMenuService::class);
            $systemRoleMenuService->save($data);
            return Json::success('success');
        } catch (\Throwable $e) {
            return Json::fail($e->getMessage(), [], $e->getCode());
        }
    }

    #[OA\Get(
        path: '/system/auth/user-list-by-role-id',
        summary: '获取角色-关联用户列表',
        security: [['Bearer' => [], 'ApiKey' => []]],
        tags: ['权限相关'],
    )]
    #[OA\Parameter(
        name: 'role_id',
        description: '角色ID',
        in: 'query',
        required: true,
        schema: new OA\Schema(type: 'string'),
    )]
    #[Permission(code: 'system:auth:role_user_list')]
    #[SimpleResponse(example: ['code' => 0, 'message' => 'success', 'data' => []])]
    public function getUsersByRoleId(Request $request): \support\Response
    {
        try {
            [$where, $format, $limit, $field, $order, $page] = $this->selectInput($request, [
                'exclude_filters' => true,
                'filter_format'   => 'simple',  // 简洁模式没有操作符
                'custom_fields'   => [
                    'user_name',
                    'real_name',
                    'mobile_phone',
                    'role_id',
                ],
            ]);
            /** @var AdminService $systemUserService */
            $systemUserService = Container::make(AdminService::class);
            $data              = $systemUserService->getUsersListByRoleId($where, $field, $page, $limit);
            return Json::success('ok', $data);
        } catch (\Throwable $e) {
            return Json::fail($e->getMessage(), [], $e->getCode());
        }

    }

    #[OA\Post(
        path: '/system/auth/save-user-role',
        summary: '保存用户-关联角色',
        security: [['Bearer' => [], 'ApiKey' => []]],
        tags: ['权限相关'],
    )]
    #[Permission(code: 'system:auth:save_user_role')]
    #[SimpleResponse(example: '{"code": 0,"msg": "success"}')]
    public function saveUserRoles(Request $request): \support\Response
    {
        try {
            $data = $request->all();
            /** @var AdminRoleService $systemUserRoleService */
            $systemUserRoleService = Container::make(AdminRoleService::class);
            $systemUserRoleService->saveUserRoles($data);
            return Json::success('success');
        } catch (\Throwable $e) {
            return Json::fail($e->getMessage(), [], $e->getCode());
        }
    }

    #[OA\Post(
        path: '/system/auth/remove-user-role',
        summary: '移除用户-关联角色',
        security: [['Bearer' => [], 'ApiKey' => []]],
        tags: ['权限相关'],
    )]
    #[Permission(code: 'system:auth:remove_user_role')]
    #[SimpleResponse(example: '{"code": 0,"msg": "ok"}')]
    public function removeUserRole(Request $request): \support\Response
    {
        try {
            $data = $request->all();
            /** @var  AdminRoleService $systemUserRoleService */
            $systemUserRoleService = Container::make(AdminRoleService::class);
            $systemUserRoleService->removeUserRole($data);
            return Json::success('ok');
        } catch (\Throwable $e) {
            return Json::fail($e->getMessage(), [], $e->getCode());
        }
    }

    #[OA\Get(
        path: '/system/auth/user-list-exclude-role-id',
        summary: '获取用户列表-排除指定角色id',
        security: [['Bearer' => [], 'ApiKey' => []]],
        tags: ['权限相关'],
    )]
    #[OA\Parameter(
        name: 'role_id',
        description: '角色ID',
        in: 'query',
        required: true,
        schema: new OA\Schema(type: 'string'),
    )]
    #[Permission(code: 'system:auth:user_list_exclude_role')]
    #[SimpleResponse(example: ['code' => 0, 'message' => 'success', 'data' => []])]
    public function getUsersExcludingRole(Request $request): \support\Response
    {
        try {
            [$where, $format, $limit, $field, $order, $page] = $this->selectInput($request, [
                'exclude_filters' => true,
                'filter_format'   => 'simple',  // 简洁模式没有操作符
                'custom_fields'   => [
                    'LIKE_user_name',
                    'LIKE_real_name',
                    'LIKE_mobile_phone',
                    'role_id',
                ]]);
            $systemUserService = Container::make(AdminService::class);
            $data              = $systemUserService->getUsersExcludingRole($where, $field, $page, $limit);
            return Json::success('ok', $data);
        } catch (\Throwable $e) {
            return Json::fail($e->getMessage(), [], $e->getCode());
        }
    }

    /**
     * @throws \core\jwt\ex\JwtRefreshTokenExpiredException
     */
    #[OA\Get(
        path: '/system/auth/refresh-token',
        summary: '刷新Token',
        security: [['Bearer' => [], 'ApiKey' => []]],
        tags: ['权限相关'],
    )]
    #[Permission(code: 'system:auth:refresh_token')]
    #[AllowAnonymous(requireToken: false, requirePermission: false)]
    #[SimpleResponse(example: '{"data": "new_access_token"}')]
    public function refreshToken(Request $request): \support\Response
    {
        try {
            $token = (new JwtToken())->refresh();
            // 返回新的access_token和refresh_token，以及过期时间
            return Json::success('ok', [
                'access_token'  => $token->accessToken,
                'refresh_token' => $token->refreshToken,
                'expires_in'    => $token->expiresIn,
                'expires_at'    => $token->expiresAt->getTimestamp(),
            ]);
        } catch (\Throwable $e) {
            throw new JwtRefreshTokenExpiredException('登录凭证失效');
        }
    }

}
