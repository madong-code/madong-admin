<?php

/**
 * 附件表新增「来源归属」字段
 *
 * - source: 附件来源（default=系统默认，plugin:{插件编码}=插件上传）
 * - 插件卸载时需要精确回收其上传资源（云对象 / 本地文件 / 附件记录）。
 *   改造前只能靠 base_path / path 的目录前缀 LIKE 反推归属，依赖卸载那一刻的
 *   存储配置：换驱动、改 dirname 或换桶后前缀就匹配不到，记录与文件都会残留；
 *   云端清理还只支持七牛一种驱动。
 * - 落库 source 后，卸载清理改为按 source 精确取记录，再逐条按记录自身的
 *   platform 调用驱动 deleteFile() 删除对象，不再依赖目录名、LIKE 与具体驱动。
 * - 存量记录默认 'default'，清理时以路径前缀作为兜底识别。
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return new class {

    public function up(Builder $schema): void
    {
        if ($schema->hasTable('sys_upload') && !$schema->hasColumn('sys_upload', 'source')) {
            $schema->table('sys_upload', function (Blueprint $table) {
                $table->string('source', 64)
                    ->default('default')
                    ->comment('来源归属:default系统 plugin:{插件编码}')
                    ->after('space');
                $table->index('source', 'idx_sys_upload_source');
            });
        }
    }

    public function down(Builder $schema): void
    {
        if ($schema->hasTable('sys_upload') && $schema->hasColumn('sys_upload', 'source')) {
            $schema->table('sys_upload', function (Blueprint $table) {
                $table->dropIndex('idx_sys_upload_source');
                $table->dropColumn('source');
            });
        }
    }
};
