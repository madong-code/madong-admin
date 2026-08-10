<?php
declare(strict_types=1);

/**
 *+------------------
 * madong
 *+------------------
 * Copyright (c) https://gitee.com/motion-code  All rights reserved.
 *+------------------
 * Author: Mr. April (405784684@qq.com)
 *+------------------
 * Official Website: http://www.madong.tech
 */

namespace core\business\install\traits;

use app\model\system\dict\Dict as SysDict;
use app\model\system\dict\DictItem as SysDictItem;
use core\io\uuid\Snowflake;

/**
 * 字典导入 Trait
 */
trait DictTrait
{
    /**
     * 运行字典种子，直接写入 sys_dict
     */
    public function runDict(): void
    {
        $this->runAdminDict();
    }

    /**
     * admin 字典直接写入 sys_dict
     */
    public function runAdminDict(): void
    {
        $dictFile = base_path('resource/data/dict/dict.php');
        if (!file_exists($dictFile)) {
            return;
        }

        $dicts         = require $dictFile;
        $dictTable     = $this->table((new SysDict())->getTable());
        $dictItemTable = $this->table((new SysDictItem())->getTable());
        $pdo           = $this->getPdo();

        $pdo->exec("TRUNCATE TABLE `{$dictItemTable}`");
        $pdo->exec("TRUNCATE TABLE `{$dictTable}`");

        foreach ($dicts as $dict) {
            $dictId = Snowflake::generate();

            $this->insert($dictTable, [
                'id'          => $dictId,
                'group_code'  => $dict['group'] ?? 'system',
                'code'        => $dict['code'] ?? '',
                'name'        => $dict['name'] ?? '',
                'sort'        => $dict['sort'] ?? 0,
                'data_type'   => $dict['data_type'] ?? 1,
                'description' => $dict['remark'] ?? '',
                'enabled'     => $dict['status'] ?? 1,
                'created_at'  => $this->currentTime,
                'updated_at'  => $this->currentTime,
                'created_by'  => 1,
                'updated_by'  => 1,
            ]);

            if (!empty($dict['items'])) {
                foreach ($dict['items'] as $item) {
                    $this->insert($dictItemTable, [
                        'id'         => Snowflake::generate(),
                        'dict_id'    => $dictId,
                        'code'       => $dict['code'] ?? '',
                        'value'      => $item['value'] ?? '',
                        'label'      => $item['label'] ?? '',
                        'color'      => $item['color'] ?? '',
                        'sort'       => $item['sort'] ?? 0,
                        'enabled'    => $item['status'] ?? 1,
                        'created_at' => $this->currentTime,
                        'updated_at' => $this->currentTime,
                        'created_by' => 1,
                        'updated_by' => 1,
                    ]);
                }
            }
        }
    }
}