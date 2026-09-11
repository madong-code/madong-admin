<?php

/**
 * 给 wf_process_instance 增加 abnormal_type 字段
 * 异常标记类型：none/handler_leave/handler_unreachable/abnormal_flow/manual_flag/自定义扩展
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return new class {

    public function up(Builder $schema): void
    {
        if ($schema->hasTable('wf_process_instance') && !$schema->hasColumn('wf_process_instance', 'abnormal_type')) {
            $schema->table('wf_process_instance', function (Blueprint $table) {
                $table->string('abnormal_type', 50)
                    ->default('none')
                    ->comment('超审异常标记类型: none正常 handler_leave离职 handler_unreachable无法联系 abnormal_flow异常流程 manual_flag手动标记 自定义扩展')
                    ->after('state');
            });
        }
    }

    public function down(Builder $schema): void
    {
        if ($schema->hasTable('wf_process_instance') && $schema->hasColumn('wf_process_instance', 'abnormal_type')) {
            $schema->table('wf_process_instance', function (Blueprint $table) {
                $table->dropColumn('abnormal_type');
            });
        }
    }
};
