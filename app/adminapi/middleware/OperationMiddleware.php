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
 * Official Website: http://www.madong.tech
 */

namespace app\adminapi\middleware;

use app\adminapi\CurrentUser;
use app\adminapi\event\system\OperationLogEvent;
use core\infrastructure\logger\Logger;
use madong\helper\Arr;
use madong\swagger\attribute\AllowAnonymous;
use madong\swagger\helper\AnnotationHelper;
use support\Container;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

#[\Attribute]
final class OperationMiddleware implements MiddlewareInterface
{

    private CurrentUser $currentUser;

    public function __construct()
    {
        $this->currentUser = Container::make(CurrentUser::class);
    }

    public function process(Request $request, callable $handler): Response
    {
        $controllerClass = $request->controller;
        $action          = $request->action;

        $oaAnnotations = AnnotationHelper::getMethodOaAnnotations($controllerClass, $action);
        $oaData        = $this->extractOaData($oaAnnotations);
        $firstOa       = Arr::first($oaData);

        $response = $handler($request);

        // 操作日志属于旁路逻辑：业务响应已生成，日志构建/落库的任何异常都不允许
        // 替换掉业务响应（否则会出现"业务已成功提交、前端却收到失败"的假失败）
        try {
            // 检查是否需要记录操作日志（跳过匿名访问）
            $allowAnonymous = AnnotationHelper::getMethodAnnotation($controllerClass, $action, AllowAnonymous::class);
            if (!$allowAnonymous || $allowAnonymous->requirePermission) {
                $logData = $this->buildLogData($request, $response, $firstOa);
                $event   = new OperationLogEvent(
                    $logData['name'],
                    $logData['oa_tags'],
                    $logData['oa_description'],
                    $logData['app'],
                    $logData['ip'],
                    $logData['ip_location'],
                    $logData['browser'],
                    $logData['os'],
                    $logData['url'],
                    $logData['class_name'],
                    $logData['action'],
                    $logData['method'],
                    $logData['param'],
                    $logData['result'],
                    $logData['user_name'],
                    $logData['user_id']
                );
                $event->dispatch();
            }
        } catch (\Throwable $e) {
            Logger::error('[OperationMiddleware] 操作日志记录失败（不影响业务响应）', [
                'url'   => $request->path(),
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);
        }

        return $response;
    }

    private function extractOaData(array $oaAnnotations): array
    {
        return array_map(function ($annotation) {
            return [
                'type'        => $annotation::class, // 注解类型（如 OpenApi\Attributes\Get）
                'summary'     => $annotation->summary, // 操作摘要
                'tags'        => $annotation->tags,    // 标签
                'description' => $annotation->description ?? '', // 描述
            ];
        }, $oaAnnotations);
    }

    private function buildLogData(Request $request, Response $response, ?array $firstOa): array
    {
        $adminInfo = $this->currentUser->admin();
        return [
            'name'           => $firstOa['summary'] ?? '未知操作', // 用 OA summary 作为操作名称
            'oa_tags'        => $firstOa['tags'] ?? [], // OA 标签
            'oa_description' => $firstOa['description'] ?? '', // OA 描述
            'app'            => $request->app,
            'ip'             => $request->getRealIp(),
            'ip_location'    => $this->getIpLocation($request->getRealIp()), // 可选：IP 归属地
            'browser'        => $this->getBrowser($request->header('user-agent')),
            'os'             => $this->getOs($request->header('user-agent')),
            'url'            => trim($request->path()),
            'class_name'     => $request->controller,
            'action'         => $request->action,
            'method'         => $request->method(),
            'param'          => $this->filterParams($request->all()), // 过滤后的参数（数组）
            'result'         => $this->formatResponse($response), // 格式化响应（数组/字符串）
            'user_name'      => $adminInfo['user_name'] ?? '匿名用户',
            'user_id'        => $adminInfo['user_id'] ?? 0,
        ];
    }

    private function getIpLocation(string $ip): string
    {
        return '未知';
    }

    private function getBrowser($user_agent): string
    {
        $user_agent = (string) ($user_agent ?? '');
        $br = 'Unknown';
        if (preg_match('/MSIE/i', $user_agent)) {
            $br = 'MSIE';
        } elseif (preg_match('/Firefox/i', $user_agent)) {
            $br = 'Firefox';
        } elseif (preg_match('/Chrome/i', $user_agent)) {
            $br = 'Chrome';
        } elseif (preg_match('/Safari/i', $user_agent)) {
            $br = 'Safari';
        } elseif (preg_match('/Opera/i', $user_agent)) {
            $br = 'Opera';
        } else {
            $br = 'Other';
        }
        return $br;
    }

    private function getOs($user_agent): string
    {
        $user_agent = (string) ($user_agent ?? '');
        $os = 'Unknown';
        if (preg_match('/win/i', $user_agent)) {
            $os = 'Windows';
        } elseif (preg_match('/mac/i', $user_agent)) {
            $os = 'Mac';
        } elseif (preg_match('/linux/i', $user_agent)) {
            $os = 'Linux';
        } else {
            $os = 'Other';
        }
        return $os;
    }

    private function filterParams($params): string
    {
        $blackList = ['password', 'old_password', 'new_password', 'content'];
        foreach ($params as $key => $value) {
            if (in_array($key, $blackList)) {
                $params[$key] = '******';
            }
        }
        return json_encode($params, JSON_UNESCAPED_UNICODE);
    }

    private function formatResponse(Response $response): array|string
    {
        // 文件下载（流式响应）不读取 body，避免大文件被整体读入内存并破坏流式输出
        $contentType = (string) $response->getHeader('content-type');
        $contentDisposition = (string) $response->getHeader('content-disposition');
        if (
            str_contains($contentType, 'application/octet-stream')
            || str_contains($contentType, 'application/force-download')
            || str_contains($contentDisposition, 'attachment')
        ) {
            return '[文件下载]';
        }

        $rawBody = $response->rawBody();
        if (str_contains($rawBody, 'json')) {
            return json_decode($rawBody, true) ?? $rawBody;
        }
        return $rawBody;
    }
}
