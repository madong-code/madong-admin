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

use app\model\system\admin\Admin;

/**
 * 管理员创建 Trait
 */
trait AdminTrait
{

    /**
     * 创建管理员
     *
     * @param array $adminParams 管理员参数
     */
    public function createAdmin(array $adminParams): void
    {
        $adminTable = $this->table((new Admin())->getTable());
        $pdo = $this->getPdo();
        
        $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM `{$adminTable}`");
        $result = $stmt->fetch();
        $adminExists = $result && $result['cnt'] > 0;

        if (!$adminExists) {
            $username = $adminParams['username'] ?? 'admin';
            $password = $adminParams['password'] ?? '123456';
            $email = $adminParams['email'] ?? 'admin@example.com';

            $this->insert($adminTable, [
                'id' => 1,
                'user_name' => $username,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'real_name' => '超级管理员',
                'nick_name' => '超级管理员',
                'email' => $email,
                'is_super' => 1,
                'enabled' => 1,
                'created_at' => $this->currentTime,
                'updated_at' => $this->currentTime,
            ]);
        }
    }
}
