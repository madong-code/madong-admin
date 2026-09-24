<?php

/**
 * 框架升级补充列
 *
 * 由 2026_09_21 / 2026_09_23 / 2026_09_24 三个增量迁移合并而来，仅用于存量环境补齐。
 * 以下列均已同步进各自建表脚本（sys_upload → 2026_07_01_000001_create_system_tables，
 * member → 2026_07_01_000003_create_member_tables），对全新安装为幂等空操作。
 *
 * - member.last_active_time: 任意有效 API 请求时更新（中间件节流写库，每用户 5 分钟最多一次），
 *   与 last_login_time（仅登录时更新）语义分离，活跃用户列表按本字段排序
 * - sys_upload.space: 附件所属存储空间（default=公开空间，private=私有空间）。
 *   同一驱动下公开空间与私有空间是互相独立的桶 / 访问域名，同一份文件两边各存一份，
 *   因此 hash 去重必须带本维度，否则切换空间后会复用另一空间的记录，
 *   返回当前空间访问不到的地址（私有地址还会因缺签名而 403），或对象根本没落到当前桶
 * - sys_upload.source: 附件来源（default=系统默认，plugin:{插件编码}=插件上传）。
 *   插件卸载需精确回收其上传资源（云对象 / 本地文件 / 附件记录），改造前只能靠
 *   base_path / path 的目录前缀 LIKE 反推归属，依赖卸载那一刻的存储配置：换驱动、
 *   改 dirname 或换桶后前缀就匹配不到，记录与文件都会残留；云端清理还只支持七牛一种驱动。
 *   落库 source 后，卸载清理改为按 source 精确取记录，再逐条按记录自身的 platform
 *   调用驱动 deleteFile() 删除对象，不再依赖目录名、LIKE 与具体驱动
 * - 以上新增列存量记录均默认 'default'，与改造前行为一致；source 清理时以路径前缀作为兜底识别
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

        if ($schema->hasTable('sys_upload') && !$schema->hasColumn('sys_upload', 'space')) {
            $schema->table('sys_upload', function (Blueprint $table) {
                $table->string('space', 50)
                    ->default('default')
                    ->comment('存储空间:default公开 private私有')
                    ->after('platform');
                $table->string('source', 64)
                    ->default('default')
                    ->comment('来源归属:default系统 plugin:{插件编码}')
                    ->after('space');
                $table->index('space', 'idx_sys_upload_space');
                $table->index('source', 'idx_sys_upload_source');
            });
        }
    }

    public function down(Builder $schema): void
    {
        if ($schema->hasTable('sys_upload') && $schema->hasColumn('sys_upload', 'space')) {
            $schema->table('sys_upload', function (Blueprint $table) {
                $table->dropIndex('idx_sys_upload_space');
                $table->dropIndex('idx_sys_upload_source');
                $table->dropColumn(['space', 'source']);
            });
        }

        if ($schema->hasTable('member') && $schema->hasColumn('member', 'last_active_time')) {
            $schema->table('member', function (Blueprint $table) {
                $table->dropIndex('idx_member_last_active_time');
                $table->dropColumn('last_active_time');
            });
        }
    }
};
