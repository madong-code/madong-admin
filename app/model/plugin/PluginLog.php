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

namespace app\model\plugin;

use core\foundation\base\BaseModel;

/**
 * 系统插件日志
 *
 * @author Mr.April
 * @since  1.0
 */
class PluginLog extends BaseModel
{
    protected $table = 'sys_plugin_log';

    /**
     * 指示是否自动维护时间戳
     *
     * @var bool
     */
    public $timestamps = true;

    protected $appends = ['created_date', 'updated_date'];

    protected $fillable = [
        'id',
        'action',
        'key',
        'pre_upgrade_version',
        'post_upgrade_version',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'id'        => 'string',
    ];}
