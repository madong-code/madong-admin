<?php

return [
    'market_host'   => 'https://madong.tech',
    'auth_code'     => env('madong.code', '669e3705b3d7d'),//授权码
    'auth_secret'   => env('madong.secret', '8439b36501df4f6185f521781d6b732'),//授权秘钥
    'response_type' => 'array',

    // 上传资源 CDN（供 full_url() 读取 config('madong.upload.app.cdn_url') 使用）
    'upload' => [
        'app' => [
            // 存储切到云之后，相对路径（如 /upload/avatar.jpeg）统一指向 CDN 域名
            'cdn_url'        => env('CDN_URL', 'https://cdn.madong.tech'),
            // 拼接在 URL 末尾的 CDN 参数，如 'imageMogr2/thumbnail/200x'
            'cdn_url_params' => env('CDN_URL_PARAMS', ''),
        ],
    ],
];
