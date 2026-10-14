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
 * 插件默认配置
 * 此文件的配置可被插件的 config/info.php 覆盖
 * 覆盖规则：info.php 中的配置会合并到此文件的配置中
 */

return [
    /**
     * 安装配置
     */
    'install'      => [
        // 是否自动创建日志目录
        'auto_mkdir_logs' => true,
    ],

    /**
     * 卸载配置
     */
    'uninstall'    => [
        // 卸载时是否删除数据表（默认 false）
        'drop_tables'         => false,
        // 卸载时是否移除合并的依赖（默认 false）
        'remove_dependencies' => false,
    ],

    /**
     * 模板资源配置
     * 插件模板复制目标位置（相对于项目根目录，即 server 的兄弟节点）
     * 结构: ['端' => '相对路径']
     */
    'template'     => [
        // 后台模板目标路径
        'admin'    => 'template/admin/src/plugin',
        // 前台模板目标路径（Nuxt3 插件目录为 src/plugin）
        'web'      => 'template/web/src/plugin',
    ],

    /**
     * 资源目录配置
     * 插件资源目录名称
     */
    'resource'     => [
        // 数据库迁移目录
        'migration' => 'database/migrations',
        // 数据库种子目录
        'seed'      => 'database/seeds',
        // 菜单资源目录
        'menu'      => 'data/menu',
        // 消息资源目录
        'message'   => 'data/message',
        // 模板资源目录
        'template'  => 'template',
    ],

    /**
     * Logo 配置
     */
    'logo'         => [
        // Logo 存储目录（相对于项目根目录）
        'path'    => 'resource/images/plugin/logo',
        // 默认 logo 文件名
        'default' => 'default.png',
        // 支持的扩展名
        'ext'     => ['png', 'jpg', 'jpeg', 'svg', 'webp'],
    ],

    /**
     * 依赖合并配置
     */
    'dependencies' => [
        // 后台依赖配置 (admin/package.json)
        'admin'   => [
            'enabled'    => true,
            'merge_dev'  => true,
            'merge_prod' => true,
        ],
        // 平台管理后台依赖配置 (platform/package.json) [新增]
        'platform' => [
            'enabled'    => true,
            'merge_dev'  => true,
            'merge_prod' => true,
        ],
        // 前台依赖配置 (web/package.json)
        'web'     => [
            'enabled'    => true,
            'merge_dev'  => true,
            'merge_prod' => true,
        ],
        // 后端依赖配置 (server/composer.json)
        'backend' => [
            'enabled'           => true,
            'merge_require'     => true,
            'merge_require_dev' => true,
        ],

        // 注意：插件「安装」流程固定只合并依赖声明（pending），不做真实下载，
        // 以免阻塞 worker 导致 SSE 连接断开、后端失联。
        // 真实下载仅由独立的「补装依赖」手动操作触发（force=true）。
        'auto_install' => false,

        // 真实安装失败时的处理策略：continue(插件安装成功，依赖标记待装) / abort(中止安装并回滚)
        'on_failure' => 'continue',

        // 版本冲突策略：skip(保留现有版本并提示) / override(覆盖为插件声明版本) / fail(中止安装)
        'conflict' => [
            'strategy' => 'skip',
        ],

        // lock 文件同步配置（避免 composer.json 与 composer.lock 的 content-hash 不一致）
        'lock' => [
            'sync'          => true,
            // update-lock: composer update --lock（仅重算 hash，不改变已锁定版本）
            'composer_mode' => 'update-lock',
            // lockfile-only: pnpm install --lockfile-only（只更新 lock，不落地 node_modules）
            'frontend_mode' => 'lockfile-only',
        ],

        // Composer 执行配置
        'composer' => [
            'bin'     => 'composer',
            // 镜像源（留空则使用全局默认源），如 https://mirrors.tencent.com/composer/
            'mirror'  => '',
            // 大项目 composer update 可能超过 10 分钟
            'timeout' => 900,
        ],

        // 前端包管理器执行配置
        'npm' => [
            // pnpm / npm / auto（按 packageManager 字段与 lock 文件自动探测）
            'manager'  => 'auto',
            // registry（留空则使用默认源），如 https://registry.npmmirror.com
            'registry' => '',
            // 大项目 pnpm install 普遍超过 10 分钟，默认 30 分钟
            'timeout'  => 1800,
        ],

        // 网络失败重试配置（仅对网络类错误重试）
        'network' => [
            'retry'       => 2,
            'retry_sleep' => 3,
        ],
    ],

    /**
     * 数据库配置
     */
    'database'     => [
        // 连接名（默认使用框架配置）
        'connection' => null,
    ],
];
