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
namespace core\foundation\base;

use Illuminate\Database\Eloquent\Relations\Pivot;

class BasePivot extends Pivot
{
    public $timestamps = false;

    protected $hidden = [];

    public function getConnectionName()
    {
        if (isset($this->connection)) {
            return $this->connection;
        }

        return parent::getConnectionName();
    }
}
