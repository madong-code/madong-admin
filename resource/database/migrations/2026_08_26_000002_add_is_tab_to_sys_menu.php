<?php

/**
 * 给 sys_menu 表增加 is_tab 字段
 * 是否显示在 tags 标签: 0否 1是
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return new class {

    public function up(Builder $schema): void
    {
        if ($schema->hasTable('sys_menu') && !$schema->hasColumn('sys_menu', 'is_tab')) {
            $schema->table('sys_menu', function (Blueprint $table) {
                $table->unsignedInteger('is_tab')
                    ->default(1)
                    ->comment('是否显示在tags标签: 0否 1是')
                    ->after('is_show');
            });
        }
    }

    public function down(Builder $schema): void
    {
        if ($schema->hasTable('sys_menu') && $schema->hasColumn('sys_menu', 'is_tab')) {
            $schema->table('sys_menu', function (Blueprint $table) {
                $table->dropColumn('is_tab');
            });
        }
    }
};
