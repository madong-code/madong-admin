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

namespace app\dao\member_sign;

use app\model\member_sign\MemberSign;
use core\foundation\base\BaseDao;

/**
 * MemberSign数据访问层
 *
 * @author Mr.April
 * @since  1.0
 */
class MemberSignDao extends BaseDao
{

    protected function setModel(): string
    {
        return MemberSign::class;
    }

}