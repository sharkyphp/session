<?php
/**
 * SharkyPHP Session Library
 *
 * 会话存储驱动统一契约：文件/Redis 等驱动都必须实现本接口
 *
 * @package SharkyPHP\\Session
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @version 2.0.0
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Session\Contracts;

interface SessionDriverInterface
{
    /**
     * 读取会话数据；不存在或已过期返回空数组
     *
     * @return array<string, mixed>
     */
    public function read(string $sessionId): array;

    /**
     * 写入会话数据
     *
     * @param array<string, mixed> $data
     * @param int                  $ttl  存活秒数
     */
    public function write(string $sessionId, array $data, int $ttl): bool;

    /**
     * 销毁指定会话
     */
    public function destroy(string $sessionId): bool;

    /**
     * 回收过期会话，返回被清理的数量
     */
    public function gc(int $maxLifetime): int;
}
