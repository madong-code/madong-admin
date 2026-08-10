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
namespace core\security\jwt\interfaces;

interface TokenStorageInterface
{
    public function save(string $jti, array $data, int $ttl, bool $addToUserIndex = true): bool;

    public function get(string $jti): ?array;

    public function delete(string $jti): bool;

    public function exists(string $jti): bool;

    public function deleteByUser(string $id, ?string $clientType = null, ?string $exceptJti = null): int;

    public function deleteOldest(string $id): bool;

    public function countByUser(string $id, ?string $clientType = null): int;

    public function getByUser(string $id): array;

    public function getUserTokens(string $id, ?string $clientType = null): array;

    public function removeFromUserIndex(string $id, string $jti): bool;
}