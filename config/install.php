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

/**
 * 前端模板下载配置（标准版精简版）
 *
 *  - 默认 Git 克隆，下载后的模板保留 .git 远程连接，便于后续 pull 更新
 *  - --method=zip 可切回归档下载，但归档产物不含 .git
 *  - 默认 GitHub，仅使用主镜像；--mirror=gitee/gitcode 可手动切换
 *  - 标准版只有 admin / web 两端，不引入 saas 的 mono / platform / 租户配置
 */
return [
    'download' => [
        'default_branch' => env('install.download_branch', 'main'),

        // 默认 Git 克隆；--method=zip 可切回归档下载
        'method' => env('install.download_method', 'git'),

        'archive_templates' => [
            'github.com'  => 'https://github.com/{path}/archive/refs/heads/{branch}.zip',
            'gitee.com'   => 'https://gitee.com/{path}/repository/archive/{branch}.zip',
            'gitcode.com' => 'https://gitcode.com/{path}/archive/{branch}.zip',
        ],

        'mirror' => env('install.download_mirror', 'github'),
        // 默认不自动回退到其他镜像；开启后主镜像缺仓库时才按 fallback_mirrors 补齐
        'fallback_enabled' => filter_var(env('install.download_fallback', false), FILTER_VALIDATE_BOOLEAN),
        'fallback_mirrors' => ['gitee', 'gitcode'],

        'mirrors' => [
            'github' => [
                'label' => 'GitHub 官方源',
                'repositories' => [
                    'single' => 'https://github.com/madong-code/madong-single.git',
                    'aura'   => 'https://github.com/madong-code/madong-aura.git',
                    'web'    => 'https://github.com/madong-code/web-nuxt.git',
                    'skills' => 'https://github.com/madong-code/madong-admin-saas-skills.git',
                ],
            ],
            'gitee' => [
                'label' => 'Gitee 国内镜像',
                'no_proxy' => true,
                'repositories' => [
                    'single' => 'https://gitee.com/motion-code/madong-single.git',
                    'aura'   => 'https://gitee.com/motion-code/madong-aura.git',
                    'web'    => 'https://gitee.com/motion-code/web-nuxt.git',
                    'skills' => 'https://gitee.com/motion-code/madong-admin-saas-skills.git',
                ],
            ],
            'gitcode' => [
                'label' => 'GitCode 国内镜像',
                'no_proxy' => true,
                'repositories' => [
                    'single' => 'https://gitcode.com/motion-code/madong-single.git',
                    'aura'   => 'https://gitcode.com/motion-code/madong-aura.git',
                    'web'    => 'https://gitcode.com/motion-code/web-nuxt.git',
                    'skills' => 'https://gitcode.com/motion-code/madong-admin-saas-skills.git',
                ],
            ],
        ],

        // admin 默认 UI 来源；--admin-variant 可覆盖
        'admin_variant' => env('install.admin_variant', 'single'),
        'admin_variants' => [
            'single' => ['label' => '管理端（single）', 'repo' => 'single'],
            'aura'   => ['label' => '管理端（aura）',   'repo' => 'aura'],
        ],

        // 标准版 separated 结构：管理端 / 会员端
        'templates' => [
            'admin' => 'template/admin',
            'web'   => 'template/web',
        ],

        // 可选附加资源
        'extra' => [
            'skills' => 'skills',
        ],
    ],
];
