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
namespace app\service\admin\content\notepad;

use app\dao\content\notepad\NotepadDocumentDao;
use app\dao\content\notepad\NotepadFolderDao;
use app\model\content\notepad\Document;
use core\foundation\base\BaseService;

class NotepadDocumentService extends BaseService
{
    private NotepadFolderDao $folderDao;

    public function __construct(NotepadDocumentDao $dao, NotepadFolderDao $folderDao)
    {
        $this->dao = $dao;
        $this->folderDao = $folderDao;
    }

    /**
     * 获取文档列表
     */
    public function getListByUser(string $userId, ?string $folderId = '', ?string $keyword = ''): array
    {
        if ($userId === '' || $userId === '0') {
            return [];
        }

        $query = $this->dao->getModel()::query()
            ->where('user_id', $userId);

        if ($folderId && $folderId !== 'recent') {
            $query->where('folder_id', $folderId);
        }

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('title', 'like', "%{$keyword}%")
                  ->orWhere('content', 'like', "%{$keyword}%");
            });
        }

        $query->orderByDesc('updated_at');

        return $query->get()->toArray();
    }

    /**
     * 获取文档详情
     */
    public function getByUser(string $userId, string $id): ?Document
    {
        if ($userId === '' || $userId === '0') {
            return null;
        }

        return $this->dao->getModel()::query()
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * 创建文档
     */
    public function createByUser(string $userId, array $data): Document
    {
        if ($userId === '' || $userId === '0') {
            throw new \RuntimeException('用户未登录，无法创建文档');
        }

        return $this->transaction(function () use ($userId, $data) {
            $doc = $this->dao->getModel()::query()->create([
                'folder_id'    => $data['folder_id'],
                'user_id'      => $userId,
                'title'        => trim((string) ($data['title'] ?? '未命名文档')),
                'content'      => $data['content'] ?? '',
                'content_html' => $data['content_html'] ?? null,
            ]);

            $folderQuery = $this->folderDao->getModel()::query()
                ->where('id', $data['folder_id'])
                ->where('user_id', $userId);
            $folderQuery->increment('doc_count');

            return $doc;
        });
    }

    /**
     * 更新文档
     *
     * @return Document|null 返回 null 表示文档不存在
     */
    public function updateByUser(string $userId, string $id, array $data): ?Document
    {
        if ($userId === '' || $userId === '0') {
            return null;
        }

        return $this->transaction(function () use ($userId, $id, $data) {
            $doc = $this->getByUser($userId, $id);
            if (!$doc) {
                return null;
            }

            if (isset($data['title'])) {
                $doc->title = trim((string) $data['title']);
            }
            if (isset($data['content'])) {
                $doc->content = $data['content'];
            }
            if (isset($data['content_html'])) {
                $doc->content_html = $data['content_html'];
            }
            if (isset($data['folder_id']) && (string) $data['folder_id'] !== (string) $doc->folder_id) {
                $oldFolderId = $doc->folder_id;
                $doc->folder_id = $data['folder_id'];

                $decQuery = $this->folderDao->getModel()::query()
                    ->where('id', $oldFolderId)
                    ->where('user_id', $userId);
                $decQuery->decrement('doc_count');

                $incQuery = $this->folderDao->getModel()::query()
                    ->where('id', $data['folder_id'])
                    ->where('user_id', $userId);
                $incQuery->increment('doc_count');
            }

            $doc->save();
            return $doc;
        });
    }

    /**
     * 删除文档
     *
     * @return bool true 成功 false 文档不存在
     */
    public function deleteByUser(string $userId, string $id): bool
    {
        if ($userId === '' || $userId === '0') {
            return false;
        }

        return $this->transaction(function () use ($userId, $id) {
            $doc = $this->getByUser($userId, $id);
            if (!$doc) {
                return false;
            }

            $folderId = $doc->folder_id;
            $doc->delete();

            $decQuery = $this->folderDao->getModel()::query()
                ->where('id', $folderId)
                ->where('user_id', $userId)
                ->where('doc_count', '>', 0);
            $decQuery->decrement('doc_count');

            return true;
        });
    }

    /**
     * 移动文档到其他文件夹
     *
     * @return bool true 成功 false 文档不存在
     */
    public function moveByUser(string $userId, string $id, string $newFolderId): bool
    {
        if ($userId === '' || $userId === '0') {
            return false;
        }

        return $this->transaction(function () use ($userId, $id, $newFolderId) {
            $doc = $this->getByUser($userId, $id);
            if (!$doc) {
                return false;
            }

            $oldFolderId = $doc->folder_id;
            $doc->folder_id = $newFolderId;
            $doc->save();

            $decQuery = $this->folderDao->getModel()::query()
                ->where('id', $oldFolderId)
                ->where('user_id', $userId);
            $decQuery->decrement('doc_count');

            $incQuery = $this->folderDao->getModel()::query()
                ->where('id', $newFolderId)
                ->where('user_id', $userId);
            $incQuery->increment('doc_count');

            return true;
        });
    }

    /**
     * 检查文件夹下是否有文档
     */
    public function hasDocuments(string $userId, string $folderId): bool
    {
        if ($userId === '' || $userId === '0') {
            return false;
        }

        $query = $this->dao->getModel()::query()
            ->where('folder_id', $folderId)
            ->where('user_id', $userId);
        return $query->exists();
    }

    /**
     * 删除指定文件夹下的所有文档
     */
    public function deleteByFolderIds(string $userId, array $folderIds): void
    {
        if ($userId === '' || $userId === '0') {
            return;
        }

        $query = $this->dao->getModel()::query()
            ->whereIn('folder_id', $folderIds)
            ->where('user_id', $userId);
        $query->delete();
    }
}