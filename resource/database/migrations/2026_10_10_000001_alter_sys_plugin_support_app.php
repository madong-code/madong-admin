<?php

/**
 * 拓宽 sys_plugin 表 support_app 字段
 *
 * 语义修正：support_app 为插件前置依赖声明（前置插件代码，多个逗号分隔），
 * 支持 string 单依赖 / array 多依赖两种 info.php 声明模式，
 * varchar(32) 无法容纳多值，拓宽至 varchar(255)
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return new class {

    public function up(Builder $schema): void
    {
        if ($schema->hasTable('sys_plugin') && $schema->hasColumn('sys_plugin', 'support_app')) {
            $schema->table('sys_plugin', function (Blueprint $table) {
                $table->string('support_app', 255)
                    ->default('admin')
                    ->comment('前置插件依赖（插件代码，多个逗号分隔）')
                    ->change();
            });
        }
    }

    public function down(Builder $schema): void
    {
        if ($schema->hasTable('sys_plugin') && $schema->hasColumn('sys_plugin', 'support_app')) {
            $schema->table('sys_plugin', function (Blueprint $table) {
                $table->string('support_app', 32)
                    ->default('admin')
                    ->comment('支持的终端')
                    ->change();
            });
        }
    }
};
