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

namespace app\adminapi\schema\response\sys_admin_type;

use OpenApi\Attributes as OA;
use madong\swagger\schema\BaseResponseDTO;


#[OA\Schema(
    title: 'SysAdminType详情响应模型',
    description: 'SysAdminType详情接口的返回数据结构'
)]
class SysAdminTypeResponse extends BaseResponseDTO
{
        #[OA\Property(
        description: '类型编码: platform-平台管理员',
        type: 'string',
        example: '示例值',
        nullable: false
    )]
    public string $code;

    #[OA\Property(
        description: '创建时间',
        type: 'integer',
        example: '1',
        nullable: true
    )]
    public ?int $created_at = null;

    #[OA\Property(
        description: '类型名称',
        type: 'string',
        example: '示例值',
        nullable: false
    )]
    public string $name;

    #[OA\Property(
        description: '更新时间',
        type: 'integer',
        example: '1',
        nullable: true
    )]
    public ?int $updated_at = null;

    #[OA\Property(
        description: '排序',
        type: 'integer',
        example: '1',
        nullable: false
    )]
    public int $sort;


}