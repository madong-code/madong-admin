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
 * Official Website: http://www.madong.cn
 */

namespace app\model\system\config;

use app\model\system\admin\Admin;
use core\foundation\base\BaseModel;

/**
 * 附件模型
 *
 * @author Mr.April
 * @since  1.0
 */
class Upload extends BaseModel
{

    /**
     * 系统默认来源（后台 / 平台自身上传）
     */
    public const SOURCE_DEFAULT = 'default';

    /**
     * 插件来源前缀，完整值形如 plugin:portal
     */
    public const SOURCE_PLUGIN_PREFIX = 'plugin:';

    /**
     * 构造插件来源标识
     */
    public static function pluginSource(string $code): string
    {
        return self::SOURCE_PLUGIN_PREFIX . $code;
    }

    /**
     * 数据表主键
     *
     * @var string
     */
    protected $primaryKey = 'id';

    protected $table = 'sys_upload';

    protected $appends = ['created_date', 'updated_date'];

    protected $fillable = [
        'id',
        'url',
        'size',
        'size_info',
        'hash',
        'filename',
        'original_filename',
        'base_path',
        'path',
        'ext',
        'content_type',
        'platform',
        'space',
        'source',
        'th_url',
        'th_filename',
        'th_size',
        'th_size_info',
        'th_content_type',
        'object_id',
        'object_type',
        'attr',
        'created_at',
        'created_by',
        'updated_at',
        'updated_by',
    ];

    protected $casts = [
        'created_by' => 'string',
        'id'         => 'string',
        'object_id'  => 'string',
        'updated_by' => 'string',
    ];

    /**
     * createds
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function createds(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Admin::class, 'id', 'created_by')->select('id', 'real_name as created_name');
    }

    public function updateds(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Admin::class, 'id', 'updated_by')->select('id', 'real_name as updated_name');
    }

}
