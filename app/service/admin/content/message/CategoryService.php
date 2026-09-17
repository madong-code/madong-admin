<?php
declare(strict_types=1);

/**
 *+------------------
 * madong - 消息分类服务（后台管理）
 *+------------------
 * Copyright (c) https://gitee.com/motion-code  All rights reserved.
 *+------------------
 * Author: Mr. April (405784684@qq.com)
 *+------------------
 * Official Website: https://madong.tech
 */

namespace app\service\admin\content\message;

use app\dao\content\message\MessageCategoryDao;
use app\dao\content\message\MessageDefinitionDao;
use app\model\content\message\Category;
use app\model\content\message\Definition;
use core\foundation\base\BaseService;

class CategoryService extends BaseService
{
    protected MessageDefinitionDao $definitionDao;

    public function __construct(MessageCategoryDao $dao, MessageDefinitionDao $definitionDao)
    {
        $this->dao            = $dao;
        $this->definitionDao  = $definitionDao;
    }

    public function getAllCategories(): array
    {
        $categories = Category::where('enabled', 1)
            ->where('pid', 0)
            ->orderBy('sort', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->toArray();

        foreach ($categories as &$category) {
            $category['definitions'] = $this->getDefinitionsByCategory($category['id']);
        }
        unset($category);

        return $categories;
    }

    public function getDefinitionsByCategory(int|string $categoryId): array
    {
        return Definition::where('category_id', $categoryId)
            ->where('enabled', 1)
            ->orderBy('sort', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->toArray();
    }

    public function getByKey(string $key): ?array
    {
        $model = Category::where('key', $key)->first();
        return $model ? $model->toArray() : null;
    }

    public function getById(int|string $id): ?array
    {
        $model = Category::find($id);
        return $model ? $model->toArray() : null;
    }

    public function getDefinitionInfo(int|string $definitionId): ?array
    {
        $model = Definition::find($definitionId);
        return $model ? $model->toArray() : null;
    }

    public function createCategory(array $data): array
    {
        $pid = $data['pid'] ?? 0;
        $level = 0;
        $path = '0';
        if ($pid > 0) {
            $parent = Category::find($pid);
            if ($parent) {
                $level = $parent->level + 1;
                $path = $parent->path ? $parent->path . '-' . $pid : '0-' . $pid;
            }
        }

        $model = Category::create([
            'pid'         => $pid,
            'key'         => $data['key'],
            'name'        => $data['name'],
            'icon'        => $data['icon'] ?? null,
            'description' => $data['description'] ?? '',
            'sort'        => $data['sort'] ?? 0,
            'level'       => $level,
            'path'        => $path,
            'is_show'     => $data['is_show'] ?? 1,
            'is_system'   => 0,
            'source'      => 'user',
            'enabled'     => $data['enabled'] ?? 1,
            'created_at'  => time(),
            'updated_at'  => time(),
        ]);
        return $model->toArray();
    }

    public function updateCategory(int|string $id, array $data): array
    {
        $model = Category::findOrFail($id);
        if ($model->is_system && isset($data['key'])) {
            unset($data['key']);
        }
        $model->fill($data);
        $model->updated_at = time();
        $model->save();
        return $model->toArray();
    }

    public function deleteCategory(int|string $id): bool
    {
        $model = Category::findOrFail($id);
        if ($model->is_system) {
            throw new \RuntimeException('系统内置分类不可删除');
        }
        if ($model->children()->count() > 0) {
            throw new \RuntimeException('该分类下有子分类，请先删除子分类');
        }
        if ($model->definitions()->count() > 0) {
            throw new \RuntimeException('该分类下有关联的消息定义，无法删除');
        }
        return $model->delete();
    }

    public function createDefinition(array $data): array
    {
        $model = Definition::create([
            'category_id'  => $data['category_id'],
            'key'          => $data['key'],
            'name'         => $data['name'],
            'description'  => $data['description'] ?? '',
            'default_on'   => $data['default_on'] ?? true,
            'nav_type'     => $data['nav_type'] ?? null,
            'nav_value'    => $data['nav_value'] ?? null,
            'sort'         => $data['sort'] ?? 0,
            'is_system'    => 0,
            'source'       => 'user',
            'enabled'      => $data['enabled'] ?? 1,
            'created_at'   => time(),
            'updated_at'   => time(),
        ]);
        return $model->toArray();
    }

    public function updateDefinition(int|string $id, array $data): array
    {
        $model = Definition::findOrFail($id);
        $model->fill($data);
        $model->updated_at = time();
        $model->save();
        return $model->toArray();
    }

    public function deleteDefinition(int|string $id): bool
    {
        $model = Definition::findOrFail($id);
        if ($model->is_system) {
            throw new \RuntimeException('系统内置定义不可删除');
        }
        return $model->delete();
    }
}