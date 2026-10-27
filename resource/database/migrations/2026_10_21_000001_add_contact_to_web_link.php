<?php

/**
 * web_link 补充联系方式列（页脚申请友链提交，后台展示）
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return new class {

    public function up(Builder $schema): void
    {
        if ($schema->hasTable('web_link') && !$schema->hasColumn('web_link', 'contact')) {
            $schema->table('web_link', function (Blueprint $table) {
                $table->string('contact', 128)
                    ->nullable()
                    ->comment('联系方式（申请友链提交）')
                    ->after('description');
            });
        }
    }

    public function down(Builder $schema): void
    {
        if ($schema->hasTable('web_link') && $schema->hasColumn('web_link', 'contact')) {
            $schema->table('web_link', function (Blueprint $table) {
                $table->dropColumn('contact');
            });
        }
    }
};
