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

namespace resource\database\seeds;

use app\model\system\admin\Admin;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    private ?array $adminParams = null;

    public function setAdminParams(array $params): void
    {
        $this->adminParams = $params;
    }

    public function run(): void
    {
        $this->runSingle();
    }

    private function runSingle(): void
    {
        Admin::truncate();

        $now = time();

        $existingAdmin = Admin::find(1);
        if ($existingAdmin) {
            if ($this->adminParams) {
                $existingAdmin->user_name = $this->adminParams['username'];
                $existingAdmin->password = password_hash($this->adminParams['password'], PASSWORD_DEFAULT);
                $existingAdmin->real_name = '超级管理员';
                $existingAdmin->nick_name = '超级管理员';
                $existingAdmin->is_super = 1;
                $existingAdmin->enabled = 1;
                $existingAdmin->email = $this->adminParams['email'] ?? 'admin@example.com';
                $existingAdmin->save();
            }
        } else {
            $username = $this->adminParams['username'] ?? 'admin';
            $password = $this->adminParams['password'] ?? '123456';

            Admin::create([
                'id'         => 1,
                'user_name'  => $username,
                'real_name'  => '超级管理员',
                'nick_name'  => '超级管理员',
                'password'   => password_hash($password, PASSWORD_DEFAULT),
                'email'      => 'admin@example.com',
                'avatar'     => '',
                'is_super'   => 1,
                'enabled'    => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
