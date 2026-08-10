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
namespace core\security\jwt\storage;

use core\security\jwt\interfaces\TokenStorageInterface;
use support\Redis;

class RedisTokenStorage implements TokenStorageInterface
{
    protected string $prefix;

    protected array $config;

    protected const USER_INDEX_PREFIX = 'user:';

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->prefix = $config['storage']['redis_prefix'] ?? 'jwt2:';
    }

    public function save(string $jti, array $data, int $ttl, bool $addToUserIndex = true): bool
    {
        $key = $this->prefix . $jti;
        $id = $data['id'] ?? '';
        $clientType = $data['client_type'] ?? '';

        Redis::setex($key, $ttl, json_encode($data));

        if ($addToUserIndex && !empty($id)) {
            $userKey  = $this->prefix . self::USER_INDEX_PREFIX . $id;
            Redis::hset($userKey, $jti, json_encode([
                'client_type' => $clientType,
                'created_at'  => $data['created_at'] ?? time(),
                'refresh_jti' => $data['refresh_jti'] ?? '',
            ]));
            Redis::expire($userKey, $this->config['ttl']['refresh'] ?? 604800);
        }

        return true;
    }

    public function get(string $jti): ?array
    {
        $key = $this->prefix . $jti;
        $data = Redis::get($key);

        if ($data === null || $data === false) {
            return null;
        }

        return json_decode((string) $data, true);
    }

    public function delete(string $jti): bool
    {
        $key = $this->prefix . $jti;

        $data = $this->get($jti);

        Redis::del($key);

        if ($data && !empty($data['id'])) {
            $userKey = $this->prefix . self::USER_INDEX_PREFIX . $data['id'];
            Redis::hdel($userKey, $jti);
        }

        return true;
    }

    public function exists(string $jti): bool
    {
        $key = $this->prefix . $jti;
        return (bool) Redis::exists($key);
    }

    public function deleteByUser(string $id, ?string $clientType = null, ?string $exceptJti = null): int
    {
        $userKey = $this->prefix . self::USER_INDEX_PREFIX . $id;
        $jtis = Redis::hgetall($userKey);

        if (empty($jtis)) {
            return 0;
        }

        $count = 0;
        foreach ($jtis as $jtiKey => $info) {
            if ($exceptJti !== null && $jtiKey === $exceptJti) {
                continue;
            }

            $infoData = json_decode((string) $info, true) ?: [];

            if ($clientType !== null && ($infoData['client_type'] ?? '') !== $clientType) {
                continue;
            }

            Redis::del($this->prefix . $jtiKey);
            Redis::hdel($userKey, $jtiKey);
            $count++;
        }

        return $count;
    }

    public function getUserTokens(string $id, ?string $clientType = null): array
    {
        $userKey = $this->prefix . self::USER_INDEX_PREFIX . $id;
        $jtis = Redis::hgetall($userKey);

        $tokens = [];
        foreach ($jtis as $jtiKey => $info) {
            $infoData = json_decode((string) $info, true) ?: [];

            if ($clientType !== null && ($infoData['client_type'] ?? '') !== $clientType) {
                continue;
            }

            $tokens[$jtiKey] = $infoData;
        }

        return $tokens;
    }

    public function deleteOldest(string $id): bool
    {
        $userKey = $this->prefix . self::USER_INDEX_PREFIX . $id;
        $jtis = Redis::hgetall($userKey);

        if (empty($jtis)) {
            return false;
        }

        $oldestJti = null;
        $oldestTime = PHP_INT_MAX;

        foreach ($jtis as $jtiKey => $info) {
            $infoData = json_decode((string) $info, true) ?: [];
            $createdAt = $infoData['created_at'] ?? 0;
            if ($createdAt < $oldestTime) {
                $oldestTime = $createdAt;
                $oldestJti = $jtiKey;
            }
        }

        if ($oldestJti !== null) {
            return $this->delete($oldestJti);
        }

        return false;
    }

    public function countByUser(string $id, ?string $clientType = null): int
    {
        $userKey = $this->prefix . self::USER_INDEX_PREFIX . $id;
        $jtis = Redis::hgetall($userKey);

        if (empty($jtis)) {
            return 0;
        }

        $count = 0;
        foreach ($jtis as $jtiKey => $info) {
            $infoData = json_decode((string) $info, true) ?: [];

            if ($clientType !== null && ($infoData['client_type'] ?? '') !== $clientType) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    public function getByUser(string $id): array
    {
        $userKey = $this->prefix . self::USER_INDEX_PREFIX . $id;
        $jtis = Redis::hgetall($userKey);

        $tokens = [];
        foreach ($jtis as $jtiKey => $info) {
            $tokenData = $this->get($jtiKey);
            if ($tokenData !== null) {
                $tokens[] = $tokenData;
            } else {
                Redis::hdel($userKey, $jtiKey);
            }
        }

        return $tokens;
    }

    public function removeFromUserIndex(string $id, string $jti): bool
    {
        $userKey = $this->prefix . self::USER_INDEX_PREFIX . $id;
        return (bool) Redis::hdel($userKey, $jti);
    }
}