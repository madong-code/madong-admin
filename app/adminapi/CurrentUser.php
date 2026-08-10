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

namespace app\adminapi;

use app\model\system\admin\Admin;
use app\model\system\menu\Menu;
use app\model\system\role\RoleMenu;
use app\service\admin\system\admin\AdminService;
use core\infrastructure\cache\CacheService;
use core\foundation\exception\handler\ForbiddenHttpException;
use core\security\jwt\JwtToken;
use core\infrastructure\logger\Logger;
use madong\swagger\attribute\Permission;
use support\Container;
use support\Log;

final  readonly class CurrentUser
{
    public const CACHE_PREFIX = 'admin_user';

    public const CACHE_EXPIRE = 3600;

    private AdminService $service;
    private CacheService $cache;

    public function __construct(AdminService $service, CacheService $cache)
    {
        $this->service = $service;
        $this->cache = $cache;
    }

    public static function generateCacheKey(int|string $uid): string
    {
        return self::CACHE_PREFIX . '_' . $uid;
    }

    public function admin(bool $toArray = false): null|Admin|array
    {
        if (!$this->getToken()) {
            return null;
        }

        $uid = $this->id();

        $cacheKey = self::generateCacheKey($uid);
        $admin = $this->cache->remember($cacheKey,  function () use ($uid) {
            return $this->service->get($uid);
        }, self::CACHE_EXPIRE);

        if ($toArray && $admin) {
            return $admin->toArray();
        }

        return $admin;
    }

    public function clearCache(int|string|null $uid = null): void
    {
        $uid = $uid ?? $this->id();
        if ($uid) {
            $cacheKey = self::generateCacheKey($uid);
            $this->cache->delete($cacheKey);
            $this->cache->delete($cacheKey . '_permissions');
        }
    }

    public function refresh(): array
    {
        if (!$this->getToken()) {
            Logger::debug("当前用户无有效 Token，无法刷新", []);
            return [];
        }
        return (new JwtToken())->refresh()->toArray();
    }

    public function id(): int|string
    {
        $token = $this->getToken();
        if (!$token) {
            return 0;
        }
        $aid = (new JwtToken())->id();
        if ($aid === null) {
            return 0;
        }
        return $aid ?? 0;
    }

    public function isSuperAdmin(): bool
    {
        $admin = $this->admin();
        return $admin && $admin->isSuperAdmin();
    }

    public function isPlatformSuper(): bool
    {
        $ext = $this->getPayload();
        $adminTypes = $ext['admin_types'] ?? [];
        return $this->hasAdminType($adminTypes, 'platform')
            && $this->hasAdminType($adminTypes, 'root');
    }

    private function hasAdminType(array $adminTypes, string $code): bool
    {
        foreach ($adminTypes as $type) {
            if (is_array($type) && !empty($type['code']) && $type['code'] === $code) {
                return true;
            }
            if (is_string($type) && $type === $code) {
                return true;
            }
        }
        return false;
    }

    public function getToken(): ?string
    {
        $request = request();
        if (empty($request)) {
            return null;
        }
        $tokenName     = config('core.security.jwt.token_name', 'Authorization');
        $authorization = $request->header($tokenName);
        if (empty($authorization) || $authorization === 'undefined') {
            $authorization = $request->get('token');
        }
        if (!$authorization || $authorization === 'undefined') {
            return null;
        }
        if (count(explode(' ', $authorization)) !== 2) {
            return null;
        }

        [$type, $token] = explode(' ', $authorization);

        if ($type !== 'Bearer') {
            return null;
        }

        if (!$token || $token === 'undefined') {
            return null;
        }

        return $token;
    }

    public function generateToken(array $userInfo, string $type = 'admin'): array
    {
        $jwt = new JwtToken();
        $tokenObj = $jwt->generate((string)$this->id(), $type, $userInfo);
        return [
            'access_token' => $tokenObj->accessToken,
            'refresh_token' => $tokenObj->refreshToken,
            'expires_in' => $tokenObj->expiresIn,
            'expires_time' => time() + $tokenObj->expiresIn
        ];
    }

    public function logout(?string $token = null): bool
    {
        $token = $token ?? $this->getToken();
        if (!$token) {
            return false;
        }
        return (new JwtToken())->logout($token);
    }

    public function getPayload(): array
    {
        try {
            $jwt = new JwtToken();
            $payload = $jwt->getPayloadFromRequest();
            return $payload['extra'] ?? $payload;
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getPermissions(): array
    {
        $admin = $this->admin();
        if (!$admin) {
            return [];
        }

        if ($admin->isSuperAdmin()) {
            return ['*'];
        }

        $roleIds = $admin->roles()->pluck('sys_role.id')->toArray();
        if (empty($roleIds)) {
            return [];
        }

        $connectionName = $admin->getConnectionName();
        $menuIds = RoleMenu::on($connectionName)
            ->whereIn('role_id', $roleIds)
            ->pluck('menu_id')
            ->unique()
            ->toArray();

        if (empty($menuIds)) {
            return [];
        }

        return Menu::on($connectionName)
            ->whereIn('id', $menuIds)
            ->where('enabled', 1)
            ->whereNotNull('code')
            ->where('code', '<>', '')
            ->pluck('code')
            ->unique()
            ->values()
            ->toArray();
    }

    public function hasPermission(string|array $codes, string $operation = 'and'): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $codes = is_array($codes) ? $codes : [$codes];
        $userPermissions = $this->getPermissions();

        if (in_array('*', $userPermissions)) {
            return true;
        }

        $operation = strtolower($operation);

        if ($operation === Permission::OPERATION_AND) {
            foreach ($codes as $code) {
                if (!in_array($code, $userPermissions)) {
                    return false;
                }
            }
            return true;
        } else if ($operation === Permission::OPERATION_OR) {
            foreach ($codes as $code) {
                if (in_array($code, $userPermissions)) {
                    return true;
                }
            }
            return false;
        }

        throw new \InvalidArgumentException("不支持的操作类型: {$operation}");
    }

    public function checkPermission(string|array $codes, string $operation = 'and'): void
    {
        if (!$this->hasPermission($codes, $operation)) {
            $codes = is_array($codes) ? $codes : [$codes];
            $codesStr = implode($operation === 'and' ? ',' : '或', $codes);
            throw new ForbiddenHttpException("缺少权限: {$codesStr}");
        }
    }

}
