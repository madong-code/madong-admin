<?php
/**
 * 数据回收站配置
 */

return [

    // ============================================
    // 全局默认配置
    // ============================================

    'default' => [
        'enabled'       => true,        // 是否启用回收站
        'strategy'      => 'physical',  // physical | logical (physical=物理删除前进回收站)
        'storage_days'  => 30,          // 回收站数据保留天数（0=永久）
        'auto_cleanup'  => true,        // 是否自动清理过期数据
    ],

    // ============================================
    // 表级配置（支持关联表恢复）
    // ============================================

    'tables' => [
        'sys_admin' => [
            'enabled'   => true,
            'relations' => [
                [
                    'name'          => 'roles',       // 模型关联方法名
                    'type'          => 'belongsToMany',
                    'related_table' => 'sys_admin_role',
                    'foreign_key'   => 'admin_id',
                    'local_key'     => 'id',
                ],
            ],
        ],
        'sys_menu' => [
            'enabled'   => true,
            'relations' => [
                [
                    'name'          => 'roles',
                    'type'          => 'belongsToMany',
                    'related_table' => 'sys_role_menu',
                    'foreign_key'   => 'menu_id',
                    'local_key'     => 'id',
                ],
            ],
        ],
        // 代码生成器-数据表：恢复时连同字段一并还原
        'generate_table' => [
            'enabled'   => true,
            'relations' => [
                [
                    'name'          => 'columns',
                    'type'          => 'hasMany',
                    'related_table' => 'generate_column',
                    'foreign_key'   => 'table_id',
                    'local_key'     => 'id',
                ],
            ],
        ],
    ],

    // ============================================
    // 排除字段（所有表通用）
    // ============================================

    'exclude_fields' => [
        'deleted_at',  // 软删除时间戳
        'updated_at',  // 更新时间（恢复时自动生成）
    ],
];
