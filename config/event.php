<?php

return [
    'adminapi.login.log' => [
        [\app\adminapi\listener\system\LoginLogListener::class, 'handle'],
    ],
    'adminapi.operation.log' => [
        [\app\adminapi\listener\system\OperationLogListener::class, 'handle'],
    ],

    'adminapi.menu.formatting' => [
        [\app\adminapi\listener\system\MenuFormattingListener::class, 'handle'],
    ],
    'adminapi.points.changed' => [
        [\app\adminapi\listener\member\PointsChangedListener::class, 'handle'],
    ],
    'adminapi.member.level.updated' => [
        [\app\adminapi\listener\member\MemberLevelUpdatedListener::class, 'handle'],
    ],
    'adminapi.review.approved' => [
        [\app\adminapi\listener\review\ReviewApprovedListener::class, 'handle'],
    ],
    'adminapi.review.rejected' => [
        [\app\adminapi\listener\review\ReviewRejectedListener::class, 'handle'],
    ],
    'adminapi.review.created' => [
        [\app\adminapi\listener\review\ReviewCreatedListener::class, 'handle'],
    ],
    'adminapi.review.canceled' => [
        [\app\adminapi\listener\review\ReviewCanceledListener::class, 'handle'],
    ],

    'adminapi.message.push' => [
        [\app\adminapi\listener\content\MessagePushListener::class, 'handle'],
    ],

    'plugin.installing' => [],
    'plugin.installed' => [],
    'plugin.uninstalling' => [],
    'plugin.uninstalled' => [
        // 按目录回收插件上传残留：{dirname}/{插件code}/ 下的云对象、本地目录与附件记录
        // 是否启用由插件 config/info.php 的 uninstall.remove_upload 决定（默认 false）
        [\app\listener\plugin\PluginUploadCleanupListener::class, 'handle'],
    ],
    'plugin.updating' => [],
    'plugin.updated' => [],
];
