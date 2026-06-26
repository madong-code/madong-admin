<?php

return [
    'adminapi.login.log' => [
        [\app\adminapi\listener\LoginLogListener::class, 'handle'],
    ],
    'adminapi.operation.log' => [
        [\app\adminapi\listener\OperationLogListener::class, 'handle'],
    ],

    'adminapi.menu.formatting' => [
        [\app\adminapi\listener\MenuFormattingListener::class, 'handle'],
    ],
    // 积分变动事件
    'adminapi.points.changed' => [
        [\app\adminapi\listener\PointsChangedListener::class, 'handle'],
    ],
    // 会员等级更新事件
    'adminapi.member.level.updated' => [
        [\app\adminapi\listener\MemberLevelUpdatedListener::class, 'handle'],
    ],
    // 审核事件
    'adminapi.review.approved' => [
        [\app\adminapi\listener\ReviewApprovedListener::class, 'handle'],
    ],
    'adminapi.review.rejected' => [
        [\app\adminapi\listener\ReviewRejectedListener::class, 'handle'],
    ],
    'adminapi.review.created' => [
        [\app\adminapi\listener\ReviewCreatedListener::class, 'handle'],
    ],

    // 会员信息拉取事件（注入标签权限码）
    'member.info.fetched' => [
        [\app\api\listener\MemberTagPermissionListener::class, 'handle'],
    ],
];
