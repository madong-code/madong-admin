<?php

declare(strict_types=1);
namespace resource\database\seeds;

use app\model\system\config\Config;
use core\io\uuid\Snowflake;
use Illuminate\Database\Seeder;

class ConfigSeeder extends Seeder
{
    public function run(): void
    {
        $configs = include base_path('resource/data/config/config.php');

        Config::truncate();

        foreach ($configs as $config) {
            Config::create([
                'id'         => Snowflake::generate(),
                'group_code' => $config['group_code'] ?? 'default',
                'code'       => $config['code'] ?? '',
                'name'       => $config['name'] ?? '',
                'content'    => is_array($config['content'] ?? '') ? json_encode($config['content'], JSON_UNESCAPED_UNICODE) : ($config['content'] ?? ''),
                'is_sys'     => $config['is_sys'] ?? 0,
                'enabled'    => $config['enabled'] ?? 1,
                'source'     => 'system',
                'created_at' => $config['created_at'] ?? time(),
                'updated_at' => $config['updated_at'] ?? time(),
                'deleted_at' => $config['deleted_at'] ?? null,
                'remark'     => $config['remark'] ?? '',
            ]);
        }
    }
}