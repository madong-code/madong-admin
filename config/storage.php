<?php

return [
    'default' => [
        'group_code' => 'default',
        'code' => 'local',
        'name' => '本地存储',
        'content' => [
            'root' => 'public',
            'dirname' => 'upload',
            'domain' => '',
        ],
        'is_sys' => 1,
    ],
    'storages' => [
        [
            'group_code' => 'default',
            'code' => 'local',
            'name' => '本地存储',
            'content' => [
                'root' => 'public',
                'dirname' => 'upload',
                'domain' => '',
            ],
            'is_sys' => 1,
        ],
        [
            'group_code' => 'default',
            'code' => 'oss',
            'name' => '阿里云OSS',
            'content' => [
                'accessKeyId' => '',
                'accessKeySecret' => '',
                'bucket' => '',
                'domain' => '',
                'endpoint' => '',
                'dirname' => '',
            ],
            'is_sys' => 1,
        ],
        [
            'group_code' => 'default',
            'code' => 'cos',
            'name' => '腾讯云COS',
            'content' => [
                'secretId' => '',
                'secretKey' => '',
                'bucket' => '',
                'domain' => '',
                'region' => '',
                'dirname' => '',
            ],
            'is_sys' => 1,
        ],
        [
            'group_code' => 'default',
            'code' => 'qiniu',
            'name' => '七牛云',
            'content' => [
                'accessKey' => '',
                'secretKey' => '',
                'bucket' => '',
                'domain' => '',
                'region' => '',
                'dirname' => '',
            ],
            'is_sys' => 1,
        ],
        [
            'group_code' => 'default',
            'code' => 's3',
            'name' => 'AWS S3',
            'content' => [
                'key' => '',
                'secret' => '',
                'bucket' => '',
                'dirname' => '',
                'domain' => '',
                'region' => '',
                'version' => '',
                'endpoint' => '',
                'acl' => '',
            ],
            'is_sys' => 1,
        ],
    ],
];
