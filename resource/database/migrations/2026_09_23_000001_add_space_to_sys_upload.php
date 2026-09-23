<?php

/**
 * 附件表新增「存储空间」字段
 *
 * - space: 附件所属存储空间（default=公开空间，private=私有空间）
 * - 同一驱动下公开空间与私有空间是互相独立的桶 / 访问域名，同一份文件两边各存一份，
 *   因此 sys_upload 的 hash 去重必须带本维度，否则切换空间后会复用另一空间的记录，
 *   返回当前空间访问不到的地址（私有地址还会因缺签名而 403），或对象根本没落到当前桶
 * - 存量记录默认 'default'，即视为公开空间附件，与改造前行为一致
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return new class {

    public function up(Builder $schema): void
    {
        if ($schema->hasTable('sys_upload') && !$schema->hasColumn('sys_upload', 'space')) {
            $schema->table('sys_upload', function (Blueprint $table) {
                $table->string('space', 50)
                    ->default('default')
                    ->comment('存储空间:default公开 private私有')
                    ->after('platform');
                $table->index('space', 'idx_sys_upload_space');
            });
        }
    }

    public function down(Builder $schema): void
    {
        if ($schema->hasTable('sys_upload') && $schema->hasColumn('sys_upload', 'space')) {
            $schema->table('sys_upload', function (Blueprint $table) {
                $table->dropIndex('idx_sys_upload_space');
                $table->dropColumn('space');
            });
        }
    }
};