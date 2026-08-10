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
 * Official Website: https://madong.tech
 */

namespace app\dao\sys_admin_type;

use app\model\sys_admin_type\SysAdminType;
use core\foundation\base\BaseDao;

/**
 * SysAdminType数据访问层
 *
 * @author Mr.April
 * @since  1.0
 */
class SysAdminTypeDao extends BaseDao
{

    protected function setModel(): string
    {
        return SysAdminType::class;
    }

}