<?php

/**
 * Playground（演示/沙盒）环境配置
 *
 * enable:      是否开启限制，未配置或为 false 时默认为正式环境
 *
 * bypass_uids: 跳过限制的用户 ID 列表，匹配的用户可正常操作
 *              默认 [1] 仅 root 用户
 *
 * routes:      受限制的路由规则列表（正则表达式格式）
 *              匹配的路由将受 methods 配置的 HTTP 方法限制
 *
 * methods:     限制的 HTTP 方法
 *
 * message:     限制提示信息
 *
 * 说明：本配置为单体版（仅 /adminapi），已适配当前单体框架的路由结构。
 * 平台端（/platformapi）相关规则不适用于单体版，故未包含。
 */
return [
    'enable'      => true,
    'bypass_uids' => [1],
    'routes'  => [
        // 系统管理
        '/adminapi/system/user',
        '/adminapi/system/user/\d+',
        '/adminapi/system/menu',
        '/adminapi/system/menu/\d+',
        '/adminapi/system/role',
        '/adminapi/system/role/\d+',
        '/adminapi/system/dept/',
        '/adminapi/system/dept/\d+',
        '/adminapi/system/post',
        '/adminapi/system/post/\d+',
        '/adminapi/system/dict',
        '/adminapi/system/dict/\d+',
        '/adminapi/system/dict-item',
        '/adminapi/system/dict-item/\d+',
        '/adminapi/system/recycle-bin',
        '/adminapi/system/recycle-bin/\d+',
        '/adminapi/system/config',
        // 运维管理
        '/adminapi/ops/crontab',
        '/adminapi/ops/crontab/\d+',
        '/adminapi/codegen/generator/code',
        '/adminapi/codegen/generator/code/\d+',
        '/adminapi/codegen/generator/code/\d+/deploy',
        // 代码生成 - 数据表管理（完整路径 /adminapi/codegen/generator/table）
        '/adminapi/codegen/generator/table/optimize',
        '/adminapi/codegen/generator/table/cleanup',
        '/adminapi/codegen/generator/table/create',
        '/adminapi/codegen/generator/table/recycle',
        '/adminapi/codegen/generator/table/recycle/restore',
        // 模块市场（安装/卸载/删除）
        '/adminapi/plugin/\w+/install',
        '/adminapi/plugin/\w+/uninstall',
        '/adminapi/plugin/\w+',
        '/adminapi/plugin',
        // 插件开发（新增/编辑/删除/打包）
        '/adminapi/plugin/develop',
        '/adminapi/plugin/develop/\d+',
        '/adminapi/plugin/develop/\d+/build',
        // WEB终端（执行命令/更新配置）
        '/adminapi/devtools/terminal',
        '/adminapi/devtools/terminal/\d+',
    ],
    'methods' => ['PUT', 'POST', 'DELETE'],
    'message' => '演示环境,不支持当前操作',
];
