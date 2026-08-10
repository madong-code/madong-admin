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

namespace app\adminapi\schema\response\member_sign;

use OpenApi\Attributes as OA;
use madong\swagger\schema\BaseResponseDTO;


#[OA\Schema(
    title: 'MemberSign详情响应模型',
    description: 'MemberSign详情接口的返回数据结构'
)]
class MemberSignResponse extends BaseResponseDTO
{
        #[OA\Property(
        description: '签到积分',
        type: 'integer',
        example: '1',
        nullable: false
    )]
    public int $points;

    #[OA\Property(
        description: '创建时间戳',
        type: 'integer',
        example: '1',
        nullable: true
    )]
    public ?int $created_at = null;

    #[OA\Property(
        description: '会员ID',
        type: 'integer',
        example: '1',
        nullable: false
    )]
    public int $member_id;

    #[OA\Property(
        description: '',
        type: 'string',
        example: '示例值',
        nullable: true
    )]
    public ?string $device_ip = null;

    #[OA\Property(
        description: '签到日期',
        type: 'string',
        example: '示例值',
        nullable: true
    )]
    public ?string $sign_date = null;

    #[OA\Property(
        description: '',
        type: 'string',
        example: '示例值',
        nullable: true
    )]
    public ?string $device_ua = null;

    #[OA\Property(
        description: '连续签到天数',
        type: 'integer',
        example: '1',
        nullable: false
    )]
    public int $continuous_days;

    #[OA\Property(
        description: '',
        type: 'integer',
        example: '1',
        nullable: true
    )]
    public ?int $updated_at = null;


}