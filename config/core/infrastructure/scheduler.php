<?php

/**
 * core 基础设施配置补齐（项目级）
 *
 * 背景：webman 启动时只自动加载 `config/` 与 `plugin/{name}/config/`，
 * `core/{package}/config/*.php` 不会被加载，因此 `config('core.infrastructure.scheduler.listen')`
 * 为空；而 `core\infrastructure\scheduler\Client::request()` 未提供默认值，
 * 会拼出 `tcp://` 空地址并抛「请求任务server失败，请检查防火墙或者配置端口是否一致」。
 *
 * 这里显式声明调度器通讯地址，与 `core/infrastructure/config/scheduler.php` 及
 * `config/process.php`（madong-scheduler 进程监听 text://{$listen}）保持一致。
 */
return [
    // 调度器通讯地址（一个项目一个端口，请勿与其他项目冲突）
    'listen' => '127.0.0.1:2001',
];
