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

use app\adminapi\event\system\LoginLogEvent;
use app\dao\system\admin\AdminDao;
use app\enum\system\PolicyPrefix;
use app\model\system\admin\Admin;
use app\adminapi\CurrentUser;
use app\service\admin\ops\logs\LoginLogService;
use core\foundation\base\BaseService;
use core\infrastructure\cache\CacheService;
use core\foundation\exception\handler\AdminException;
use core\security\jwt\JwtToken;
use core\foundation\tool\RSAService;
use support\Container;
use Webman\Event\Event;

/**
 * @method getAdminInfo(string $username)
 * @method getAdminById($uid, $withoutScopes = null)
 * @method getList(mixed $where, mixed $field, mixed $page, mixed $limit, mixed $order, array $array, false $false)
 * @method getUsersListByRoleId(mixed $where, mixed $field, mixed $page, mixed $limit)
 * @method getAdminByName(string $username, array|null $withoutScopes)
 */
class AdminService extends BaseService
{

    public function __construct(AdminDao $dao)
    {
        $this->dao = $dao;
    }

    public function save(array $data): Admin|null
    {
        try {
            return $this->transaction(function () use ($data) {
                $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
                $roles            = $data['role_id_list'] ?? [];
                $posts            = $data['post_id_list'] ?? [];
                $depts            = array_filter(explode(',', $data['dept_id_list'] ?? ''));
                $mainDeptId       = $data['main_dept_id'] ?? null;
                $mainPosId        = $data['main_post_id'] ?? null;
                unset($data['role_id_list'], $data['post_id_list'], $data['dept_id_list'], $data['main_dept_id'], $data['main_post_id']);
                $model = $this->dao->save($data);

                $this->updateModel($model, $data, $depts, $posts, $roles);
                $this->syncRoles($model, $roles);
                $this->syncMainInfo($model, $mainDeptId, $mainPosId);
                return $model;
            });
        } catch (\Throwable $e) {
            throw new AdminException($e->getMessage());
        }
    }

    public function update(int|string $id, array $data): ?Admin
    {
        try {
            return $this->transaction(function () use ($id, $data) {
                $this->updatePasswordIfNeeded($data);
                $roles = $data['role_id_list'] ?? [];
                $posts = $data['post_id_list'] ?? [];
                $depts = $data['dept_id_list'] ?? [];
                $mainDeptId = $data['main_dept_id'] ?? null;
                $mainPosId  = $data['main_post_id'] ?? null;
                unset($data['role_id_list'], $data['post_id_list'], $data['dept_id_list'], $data['main_dept_id'], $data['main_post_id']);
                $model = $this->dao->getModel()
                    ->findOrFail($id);
                $this->updateModel($model, $data, $depts, $posts, $roles);
                $this->syncRoles($model, $roles);
                $this->syncMainInfo($model, $mainDeptId, $mainPosId);
                return $model;
            });
        } catch (\Throwable $e) {
            throw new AdminException($e->getMessage());
        }
    }

    private function updatePasswordIfNeeded(array &$data): void
    {
        if (isset($data['password'])) {
            $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
    }

    private function updateModel(Admin $model, array $data, array $depts, array $posts, array $roles): void
    {
        $model->fill($data);
        $model->save();
        $model->depts()->sync($depts);
        $model->posts()->sync($posts);
        $model->roles()->sync($roles);
    }

    private function syncRoles(Admin $model, array $roles): void
    {
        $model->roles()->sync($roles);
    }

    private function syncMainInfo(Admin $model, ?string $mainDeptId, ?string $mainPosId): void
    {
        $mainInfo = \app\model\system\admin\AdminMain::updateOrCreate(
            ['admin_id' => (string)$model->id],
            [
                'main_dept_id' => $mainDeptId,
                'main_post_id'  => $mainPosId,
            ]
        );
    }

    public function destroy($id, $force): mixed
    {
        $ret = $this->dao->count([['id', 'in', $id], ['is_super', '=', 1]]);
        if ($ret > 0) {
            throw new AdminException('系统内置用户，不允许删除');
        }
        return $this->dao->destroy($id);
    }

    public function locked(array|string $id): void
    {
        try {
            if (is_string($id)) {
                $id = array_map('trim', explode(',', $id));
            }
            $ret = $this->dao->count([['id', 'in', $id], ['is_super', '=', 1]]);
            if ($ret > 0) {
                throw new AdminException('系统内置用户，不允许冻结');
            }
            $this->dao->batchUpdate($id, ['is_locked' => 1]);
        } catch (\Throwable $e) {
            throw new AdminException($e->getMessage());
        }
    }

    public function unLocked(array|string $id): void
    {
        try {
            if (is_string($id)) {
                $id = array_map('trim', explode(',', $id));
            }
            $this->dao->batchUpdate($id, ['is_locked' => 0]);
        } catch (\Throwable $e) {
            throw new AdminException($e->getMessage());
        }
    }

    public function login(string $username, string $password = '', string $type = 'admin', string $grantType = 'default', array $params = []): array
    {
        $defaultConnection = config('database.default', 'mysql');
        $adminInfo = Admin::on($defaultConnection)
            ->where('user_name', $username)
            ->first();

        $this->validateAdminStatus($adminInfo);
        $this->validatePassword($adminInfo, $password, $grantType);

        [$userInfo, $token] = $this->generateTokenData($adminInfo, $type);
        $this->emitLoginSuccessEvent(array_merge($userInfo, $token));
        return $token ?? [];
    }

    public function thirdPartyLogin(mixed $acctId, mixed $appId, mixed $appSecret, mixed $userName, string $type = 'admin'): array
    {
        $adminInfo = $this->getAdminByName($userName);
        $this->validateAdminStatus($adminInfo);
        $this->validateThirdPartyApp($acctId, $appId, $appSecret);
        [$userInfo, $token] = $this->generateTokenData($adminInfo, $type);
        $this->emitLoginSuccessEvent(array_merge($userInfo, $token));
        return $token ?? [];
    }

    private function validateThirdPartyApp(mixed $acctId, mixed $appId, mixed $appSecret)
    {
        if ($acctId !== config('app.acct_id')) {
            throw new AdminException('第三方应用不存在或已删除');
        }
        if ($appId !== config('app.app_id')) {
            throw new AdminException('第三方应用ID错误');
        }
        if ($appSecret !== config('app.app_secret')) {
            throw new AdminException('第三方应用ID或密钥错误');
        }
    }

    private function validateAdminStatus(?Admin $adminInfo): void
    {
        if (!$adminInfo) {
            throw new AdminException('账号或密码错误，请重新输入!');
        }
        if ($adminInfo->enabled === 0) {
            throw new AdminException('您已被禁止登录!');
        }
        if ($adminInfo->is_locked === 1) {
            throw new AdminException('您的账号已被锁定，禁止登录!');
        }
    }

    private function validatePassword(Admin $adminInfo, string $password, string $grantType): void
    {
        if (!in_array($grantType, ['sms', 'refresh_token']) && !password_verify($password, $adminInfo->password)) {
            $msg = '账号或密码错误，请重新输入!';
            $this->emitLoginFailedEvent($adminInfo->toArray(), $msg);
            throw new AdminException($msg);
        }
    }

    private function generateTokenData(Admin $adminInfo, string $type): array
    {
        $loginIp = request()->getRealIp();
        $userAgent = request()->header('user-agent', '');
        $browser = $this->getBrowser($userAgent);
        $os = $this->getOs($userAgent);
        $ipLocation = $this->getIpLocation($loginIp);
        
        $adminInfo->login_time = time();
        $adminInfo->login_ip   = $loginIp;
        $adminInfo->save();
        $userInfo = $adminInfo->makeHidden([
            'password',
            'backend_setting',
            'created_by',
            'updated_by',
            'created_at',
            'deleted_at',
            'remark',
            'created_date',
            'updated_date',
        ])->toArray();
        
        $userInfo['ip'] = $loginIp;
        $userInfo['ip_location'] = $ipLocation;
        $userInfo['browser'] = $browser;
        $userInfo['os'] = $os;

        $adminTypes = $adminInfo->getTypes();
        $userInfo['admin_types'] = $adminTypes;
        $userInfo['is_super'] = (int)$adminInfo->is_super;

        $jwt = new JwtToken();
        $tokenObj = $jwt->generate((string)$adminInfo->id, $type, $userInfo);
        
        $token = [
            'access_token' => $tokenObj->accessToken,
            'refresh_token' => $tokenObj->refreshToken,
            'expires_in' => $tokenObj->expiresIn,
            'client_id' => $this->generateUniqueId($loginIp),
            'expires_time' => time() + $tokenObj->expiresIn,
        ];
        
        return [$userInfo, $token];
    }

    private function emitLoginSuccessEvent(array $tokenData): void
    {
        $loginIp = request()->getRealIp();
        $event = new LoginLogEvent(
            '登录成功',
            request()->app,
            $loginIp,
            $this->getIpLocation($loginIp),
            $this->getBrowser(request()->header('user-agent', '')),
            $this->getOs(request()->header('user-agent', '')),
            0,
            '登录成功',
            $tokenData['user_name'],
            $tokenData['id'],
            time(),
            $tokenData['access_token'],
            $tokenData['expires_time']
        );
        $event->dispatch();
    }

    private function emitLoginFailedEvent(array $adminInfo, string $message): void
    {
        $loginIp = request()->getRealIp();
        $event = new LoginLogEvent(
            '登录失败',
            request()->app,
            $loginIp,
            $this->getIpLocation($loginIp),
            $this->getBrowser(request()->header('user-agent', '')),
            $this->getOs(request()->header('user-agent', '')),
            -1,
            $message,
            $adminInfo['user_name'],
            $adminInfo['id'],
            time(),
            '',
            time()
        );
        $event->dispatch();
    }
    
    private function getBrowser(string $userAgent): string
    {
        $br = 'Unknown';
        if (preg_match('/MSIE/i', $userAgent)) {
            $br = 'MSIE';
        } elseif (preg_match('/Firefox/i', $userAgent)) {
            $br = 'Firefox';
        } elseif (preg_match('/Chrome/i', $userAgent)) {
            $br = 'Chrome';
        } elseif (preg_match('/Safari/i', $userAgent)) {
            $br = 'Safari';
        } elseif (preg_match('/Opera/i', $userAgent)) {
            $br = 'Opera';
        } else {
            $br = 'Other';
        }
        return $br;
    }
    
    private function getOs(string $userAgent): string
    {
        $os = 'Unknown';
        if (preg_match('/win/i', $userAgent)) {
            $os = 'Windows';
        } elseif (preg_match('/mac/i', $userAgent)) {
            $os = 'Mac';
        } elseif (preg_match('/linux/i', $userAgent)) {
            $os = 'Linux';
        } else {
            $os = 'Other';
        }
        return $os;
    }
    
    private function getIpLocation(string $ip): string
    {
        if (empty($ip) || in_array($ip, ['127.0.0.1', '::1', 'localhost', '0.0.0.0'])) {
            return '本地';
        }

        try {
            $client = new \GuzzleHttp\Client([
                'timeout' => 2,
                'connect_timeout' => 1,
                'verify' => false
            ]);

            $url = "http://int.dpool.sina.com.cn/iplookup/iplookup.php?format=json&ip=" . $ip;
            $response = $client->get($url);

            $content = $response->getBody()->getContents();

            $data = json_decode($content, true);
            if (isset($data['ret']) && $data['ret'] === 1 && !empty($data['city'])) {
                $location = $data['city'];
                if (!empty($data['province']) && strpos($data['province'], $data['city']) === false) {
                    $location = $data['province'] . ' ' . $location;
                }
                return $location;
            }
        } catch (\Throwable $e) {
        }

        return '未知';
    }

    public function updateProfile(string|int $id, array $data, ?string $connectionName = null): void
    {
        $this->transaction(function () use ($id, $data, $connectionName) {
            $this->resolveProfileAdmin($id, $connectionName)->fill($data)->save();
            $this->clearProfileCache($id);
        });
    }

    public function updateProfilePassword(string|int $id, string $oldPassword, string $newPassword, ?string $connectionName = null): void
    {
        $this->transaction(function () use ($id, $oldPassword, $newPassword, $connectionName) {
            $admin = $this->resolveProfileAdmin($id, $connectionName);
            if (!password_verify($oldPassword, $admin->password)) {
                throw new AdminException('旧密码错误，请重新输入!');
            }
            $admin->fill(['password' => password_hash($newPassword, PASSWORD_DEFAULT)])->save();
            $this->clearProfileCache($id);
        });
    }

    public function updateProfileAvatar(string|int $id, string $avatarUrl, ?string $connectionName = null): void
    {
        $this->transaction(function () use ($id, $avatarUrl, $connectionName) {
            $this->resolveProfileAdmin($id, $connectionName)->fill(['avatar' => $this->getPathFromUrl($avatarUrl)])->save();
            $this->clearProfileCache($id);
        });
    }

    private function resolveProfileAdmin(string|int $id, ?string $connectionName): Admin
    {
        if ($connectionName) {
            return Admin::on($connectionName)->findOrFail($id);
        }
        return $this->dao->getModel()->findOrFail($id);
    }

    private function clearProfileCache(string|int $id): void
    {
        /** @var CacheService $cache */
        $cache = Container::make(CacheService::class);
        $baseKey = CurrentUser::generateCacheKey((string)$id);
        $cache->delete($baseKey);
    }

    public function updateUserPreferences(string|int $id, array $data = []): void
    {
        $this->transaction(function () use ($id, $data) {
            unset($data['id']);
            return $this->dao->update(['id' => $id], ['backend_setting' => $data]);
        });
    }

    public function kickoutByTokenValueUser($token): void
    {
        $this->transaction(function () use ($token) {
            /** @var LoginLogService $systemLoginLogService */
            $systemLoginLogService = Container::make(LoginLogService::class);
            $loginLog = $systemLoginLogService->getModel()
                ->where('key', $token)
                ->firstOrFail();

            $loginLog->update([
                'expires_at' => time(),
                'remark'     => '强制下线',
                'updated_at' => time(),
            ]);

            $result = JwtToken::addToBlacklist($token, true);
            if (!$result) {
                throw new AdminException('操作失败');
            }
        });
    }

    public function batchDelete(array $ids): array
    {
        try {
            return $this->transaction(function () use ($ids) {
                $superUserCount = $this->dao->getModel()->whereIn('id', $ids)
                    ->where('is_super', 1)
                    ->count();
                if ($superUserCount > 0) {
                    throw new AdminException('系统内置用户不允许删除');
                }

                $admins = $this->dao->getModel()->whereIn('id', $ids)->get();
                foreach ($admins as $admin) {
                    $admin->roles()->detach();
                    $admin->depts()->detach();
                }

                $deleteCount = $this->dao->destroy($ids);
                if ($deleteCount <= 0) {
                    throw new AdminException('删除失败，未找到有效用户');
                }
                return ['id' => $ids];
            });
        } catch (\Throwable $e) {
            throw new AdminException($e->getMessage());
        }
    }

    private function generateUniqueId(string|null $currentIp = null): string
    {
        if (empty($currentIp)) {
            $currentIp = request()->getRemoteIp();
        }
        return uniqid($currentIp . '-', true);
    }

    private function getPathFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        return $path ?: $url;
    }

    private function validateRsaKeys($keyId, $encryptedPassword): string
    {
        $cache      = Container::make(CacheService::class, []);
        $privateKey = $cache->get("rsa_private_key_$keyId");
        if (!$privateKey) {
            throw new AdminException('私钥不存在或已过期，请刷新页面重试');
        }
        return RSAService::decrypt($encryptedPassword, $privateKey);
    }
}
