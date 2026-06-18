<?php
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

namespace core\exception\handler;

use core\exception\handler\BaseException;

/**
 * 自定义异常类
 *
 * @author Mr.April
 * @since  1.0
 */
class AdminException extends BaseException
{

    /**
     * @var int
     */
    public int $statusCode = -1;

    /**
     * @var int
     */
    public int $errorCode = -1;

}
