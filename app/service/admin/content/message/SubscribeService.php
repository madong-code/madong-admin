<?php
declare(strict_types=1);

/**
 *+------------------
 * madong - 消息订阅服务
 *+------------------
 * Copyright (c) https://gitee.com/motion-code  All rights reserved.
 *+------------------
 * Author: Mr. April (405784684@qq.com)
 *+------------------
 * Official Website: https://madong.tech
 */

namespace app\service\admin\content\message;

use app\dao\content\message\MessageSubscribeDao;
use app\model\content\message\Category;
use app\model\content\message\Definition;
use app\model\content\message\Subscribe;
use core\foundation\base\BaseService;

class SubscribeService extends BaseService
{
    public function __construct(MessageSubscribeDao $dao)
    {
        $this->dao = $dao;
    }

    public function getSubscriptions(string $userId): array
    {
        return Subscribe::where('user_id', $userId)
            ->get()
            ->toArray();
    }

    public function getSubscriptionsGrouped(
        string $userId,
        int $page = 1,
        int $limit = 15,
        ?string $keyword = null,
    ): array {
        $unsubscribedIds = Subscribe::where('user_id', $userId)
            ->pluck('definition_id')
            ->map(fn($id) => (int)$id)
            ->toArray();
        $unsubscribedSet = array_flip($unsubscribedIds);

        $defQuery = Definition::with([
            'category',
            'templates' => function ($q) {
                $q->where('enabled', 1)->orderByRaw("FIELD(type, 'system', 'email', 'sms', 'webhook')");
            },
        ])
            ->where('enabled', 1)
            ->whereHas('category', function ($q) use ($keyword) {
                $q->where('enabled', 1)->where('pid', 0);
                if ($keyword !== null && $keyword !== '') {
                    $q->where('name', 'like', "%{$keyword}%");
                }
            })
            ->orderBy(
                Category::select('sort')
                    ->whereColumn('id', 'sys_message_definition.category_id')
                    ->limit(1)
            )
            ->orderBy('sort');

        $total = $defQuery->count();
        /** @var \Illuminate\Database\Eloquent\Collection|Definition[] $rows */
        $rows = $defQuery->forPage($page, $limit)->get();

        $items = [];
        foreach ($rows as $def) {
            $defId = (int)$def->id;
            $cat = $def->relationLoaded('category') ? $def->category : null;

            $templates = $def->relationLoaded('templates') ? $def->templates : collect();
            $contentTemplate = '';
            if ($templates->isNotEmpty()) {
                $sysTemplate = $templates->firstWhere('type', 'system');
                if ($sysTemplate) {
                    $contentTemplate = $sysTemplate->content_template ?? '';
                } else {
                    $contentTemplate = $templates->first()->content_template ?? '';
                }
            }

            $items[] = [
                'definition_id'   => $defId,
                'module_id'       => $defId,
                'module_key'      => $def->key,
                'module_name'     => $def->name,
                'description'     => $def->description ?? '',
                'content_template' => $contentTemplate,
                'category_id'     => (int)$def->category_id,
                'category_key'    => $cat ? $cat->key : '',
                'category_name'   => $cat ? $cat->name : '',
                'is_subscribed'   => !isset($unsubscribedSet[$defId]),
            ];
        }

        return compact('items', 'total');
    }

    public function setSubscription(
        string $userId,
        int|string|null $definitionId = null,
        bool $subscribe = true,
    ): void {
        if ($definitionId === null) {
            throw new \InvalidArgumentException('definition_id 不能为空');
        }

        $model = Subscribe::where('user_id', $userId)
            ->where('definition_id', $definitionId)
            ->first();

        if ($subscribe) {
            if ($model) {
                $model->delete();
            }
        } else {
            if (!$model) {
                $model = new Subscribe();
                $model->fill([
                    'user_id'       => $userId,
                    'definition_id' => $definitionId,
                ]);
                $model->save();
            }
        }
    }

    public function batchSetSubscriptions(string $userId, array $settings): bool
    {
        try {
            foreach ($settings as $setting) {
                if (isset($setting['definition_id'])) {
                    $defId = $setting['definition_id'];
                } elseif (isset($setting['module_id'])) {
                    $defId = $setting['module_id'];
                } else {
                    continue;
                }

                $this->setSubscription(
                    $userId,
                    $defId,
                    (bool)($setting['is_subscribed'] ?? true),
                );
            }
            return true;
        } catch (\Throwable $e) {
            throw new \Exception('批量设置订阅失败：' . $e->getMessage());
        }
    }

    public function isSubscribed(
        string $userId,
        int|string|null $definitionId = null,
    ): bool {
        if ($definitionId === null) {
            return false;
        }

        return !Subscribe::where('user_id', $userId)
            ->where('definition_id', $definitionId)
            ->exists();
    }

    public function initUserSubscriptions(string $userId): void
    {
    }
}