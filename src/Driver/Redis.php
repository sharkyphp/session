<?php
/**
 * SharkyPHP Session Library
 *
 * Redis 会话驱动（依赖 sharkyphp/redis 子包）
 *
 * 直接使用 Redis 子包管理器的便捷方法（默认连接），过期交给 Redis 的 TTL 处理。
 *
 * @package SharkyPHP\\Session
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Session\Driver;

use Sharky\Redis\Redis as RedisManager;

class Redis extends AbstractDriver
{
    public function __construct(
        private RedisManager $redis,
        array $config = []
    ) {
        parent::__construct($config);
    }

    public function read(string $sessionId): array
    {
        $raw = $this->redis->get($this->key($sessionId));

        if ($raw === null || $raw === false || $raw === '') {
            return [];
        }

        $data = $this->unserialize((string) $raw);

        return is_array($data) ? $data : [];
    }

    public function write(string $sessionId, array $data, int $ttl): bool
    {
        // ttl <= 0 时传 null，表示不过期
        return $this->redis->set($this->key($sessionId), $this->serialize($data), $ttl > 0 ? $ttl : null);
    }

    public function destroy(string $sessionId): bool
    {
        return $this->redis->delete($this->key($sessionId)) > 0;
    }

    /**
     * Redis 依赖 TTL 自动过期，无需扫描回收，返回 0
     */
    public function gc(int $maxLifetime): int
    {
        return 0;
    }

    /**
     * 底层 Redis 子包管理器
     */
    public function manager(): RedisManager
    {
        return $this->redis;
    }
}
