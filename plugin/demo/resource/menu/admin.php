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
 * demo 插件菜单 - 同步命令适配入口
 *
 * madong:migrate-plugin-menu 命令固定读取 plugin/{name}/resource/menu/{admin,web}.php；
 * 而插件安装（madong-plugin:install）按 config/info.php 的 resource.menu 读取
 * resource/data/menu/{admin,web}.php。此处统一转发到主定义，避免双份维护。
 */

return require dirname(__DIR__) . '/data/menu/admin.php';
