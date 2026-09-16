<?php

/**
 * Playground（演示/沙盒）环境配置
 *
 * enable:      是否开启限制，通过环境变量 PLAYGROUND_ENABLE 控制
 *              .env 中未配置或为 false 时默认为正式环境（拦截不生效）
 *              演示环境设置 PLAYGROUND_ENABLE=true 开启
 *
 * bypass_uids: 跳过限制的用户 ID 列表，匹配的用户可正常操作
 *              默认 [1] 仅 root 用户
 *
 * routes:        受限制的路由规则列表（正则表达式格式）
 *                匹配的路由将受 methods 配置的 HTTP 方法限制
 *
 * methods:       限制的 HTTP 方法
 *
 * route_methods: 按路由单独指定的限制方法（键为正则路由, 值为方法数组）
 *                优先于全局 methods, 适用于 GET 类敏感操作（如 WEB终端 SSE 执行命令）
 *
 * message:       限制提示信息
 *
 * 说明：本配置为单体版（仅 /adminapi），已适配当前单体框架的路由结构。
 * 平台端（/platformapi）相关规则不适用于单体版，故未包含。
 */
return [
    'enable'      => env('PLAYGROUND_ENABLE', false),
    'bypass_uids' => [1],
    'routes'  => [
        // 系统管理
        // 用户（/system/admin）、角色（/system/role）、部门（/system/dept）、职位（/system/post）：
        // 允许新增/编辑，仅拦截删除（route_methods），防止演示环境误删基础数据导致工作流无法运行
        '/adminapi/system/menu',
        '/adminapi/system/menu/\d+',
        '/adminapi/system/dict',
        '/adminapi/system/dict/\d+',
        '/adminapi/system/dict-item',
        '/adminapi/system/dict-item/\d+',
        '/adminapi/system/recycle',
        '/adminapi/system/recycle/\d+',
        '/adminapi/system/recycle/\d+/restore',
        '/adminapi/system/recycle/restore',
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
        // install/uninstall 为 GET SSE 流式接口，在 route_methods 中单独限制 GET
        '/adminapi/plugin/[^/]+',
        '/adminapi/plugin',
        // 插件开发（新增/编辑/删除/打包）
        '/adminapi/plugin/develop',
        '/adminapi/plugin/develop/\d+',
        '/adminapi/plugin/develop/\d+/build',
        // WEB终端（执行命令/更新配置，真实路由 /adminapi/terminal*）
        // GET /adminapi/terminal 为 SSE 执行命令（build/install 等任务），在 route_methods 中单独限制 GET
        '/adminapi/terminal/config',
        '/adminapi/terminal/execute',
        '/adminapi/terminal',
        // 工作流插件 - 流程设计（新增/编辑/删除/批量删除/更新定义/部署/重新部署）
        '/adminapi/wf/design',
        '/adminapi/wf/design/\d+',
        '/adminapi/wf/design/\d+/update-define',
        '/adminapi/wf/design/\d+/deploy',
        '/adminapi/wf/design/\d+/redeploy',
        // 工作流插件 - 表单设计（新增/编辑/删除/更新设计/保存布局/删除布局）
        '/adminapi/wf/form-design',
        '/adminapi/wf/form-design/update/\d+',
        '/adminapi/wf/form-design/delete/\d+',
        '/adminapi/wf/form-design/\d+/update-design',
        '/adminapi/wf/form-design/save-layout',
        '/adminapi/wf/form-design/layout/\d+',
        // 工作流插件 - 流程类型（新增/编辑/删除/批量删除）
        '/adminapi/wf/category',
        '/adminapi/wf/category/\d+',
    ],
    'methods' => ['PUT', 'POST', 'DELETE'],
    // 按路由单独指定的限制方法（覆盖全局 methods）
    'route_methods' => [
        // WEB终端 SSE 执行命令（GET 请求，build.admin/安装依赖/重新发布等任务走此接口）
        '/adminapi/terminal' => ['GET'],
        // 用户/角色/部门/职位仅拦截删除（新增/编辑放行，防止误删导致工作流无法运行）
        '/adminapi/system/admin' => ['DELETE'],
        '/adminapi/system/admin/\d+' => ['DELETE'],
        '/adminapi/system/role' => ['DELETE'],
        '/adminapi/system/role/\d+' => ['DELETE'],
        '/adminapi/system/dept' => ['DELETE'],
        '/adminapi/system/dept/\d+' => ['DELETE'],
        '/adminapi/system/post' => ['DELETE'],
        '/adminapi/system/post/\d+' => ['DELETE'],
        // 模块市场 安装/卸载（GET SSE 流式接口，[^/]+ 兼容含连字符的插件标识）
        '/adminapi/plugin/[^/]+/install' => ['GET'],
        '/adminapi/plugin/[^/]+/uninstall' => ['GET'],
    ],
    'message' => '演示环境,不支持当前操作',
];
