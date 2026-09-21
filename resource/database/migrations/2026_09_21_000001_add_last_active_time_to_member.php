<?php

/**
 * 会员表新增最后活跃时间字段
 *
 * - last_active_time: 任意有效 API 请求时更新（中间件节流写库，每用户 5 分钟最多一次）
 * - 与 last_login_time（仅登录时更新）语义分离，活跃用户列表按本字段排序
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return new class {

    public function up(Builder $schema): void
    {
        if ($schema->hasTable('member') && !$schema->hasColumn('member', 'last_active_time')) {
            $schema->table('member', function (Blueprint $table) {
                $table->integer('last_active_time')
                    ->nullable()
                    ->comment('最后活跃时间戳(任意有效请求节流更新)')
                    ->after('last_login_time');
                $table->index('last_active_time', 'idx_member_last_active_time');
            });
        }
    }

    public function down(Builder $schema): void
    {
        if ($schema->hasTable('member') && $schema->hasColumn('member', 'last_active_time')) {
            $schema->table('member', function (Blueprint $table) {
                $table->dropIndex('idx_member_last_active_time');
                $table->dropColumn('last_active_time');
            });
        }
    }
};
