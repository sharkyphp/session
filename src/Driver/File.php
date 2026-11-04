<?php
/**
 * SharkyPHP Session Library
 *
 * 文件会话驱动
 *
 * @package SharkyPHP\\Session
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Session\Driver;

class File extends AbstractDriver
{
    private string $path;

    /**
     * @param array<string, mixed> $config 需包含 path（会话文件目录）
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->path = (string) ($config['path'] ?? (sys_get_temp_dir() . '/sharky_session'));

        $this->ensureDirectory();
    }

    public function read(string $sessionId): array
    {
        $file = $this->filePath($sessionId);

        if (! is_file($file)) {
            return [];
        }

        $raw = file_get_contents($file);
        if ($raw === false || $raw === '') {
            return [];
        }

        $payload = $this->unserialize($raw);
        if (! is_array($payload)) {
            return [];
        }

        // 过期则删除并视为空会话
        $expiresAt = (int) ($payload['expires_at'] ?? 0);
        if ($expiresAt > 0 && time() > $expiresAt) {
            @unlink($file);

            return [];
        }

        $data = $payload['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    public function write(string $sessionId, array $data, int $ttl): bool
    {
        $payload = [
            'data'       => $data,
            'expires_at' => $ttl > 0 ? time() + $ttl : 0,
        ];

        return file_put_contents(
            $this->filePath($sessionId),
            $this->serialize($payload),
            LOCK_EX
        ) !== false;
    }

    public function destroy(string $sessionId): bool
    {
        $file = $this->filePath($sessionId);

        if (! is_file($file)) {
            return false;
        }

        return @unlink($file);
    }

    public function gc(int $maxLifetime): int
    {
        $files = glob($this->path . '/*.sess');

        if ($files === false) {
            return 0;
        }

        $collected = 0;
        $now       = time();

        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }

            $raw = file_get_contents($file);
            if ($raw === false || $raw === '') {
                continue;
            }

            $payload   = $this->unserialize($raw);
            $expiresAt = is_array($payload) ? (int) ($payload['expires_at'] ?? 0) : 0;

            // 无过期时间的按文件 mtime 兜底判断
            $expired = $expiresAt > 0
                ? $now > $expiresAt
                : ($maxLifetime > 0 && ($now - filemtime($file)) > $maxLifetime);

            if ($expired && @unlink($file)) {
                $collected++;
            }
        }

        return $collected;
    }

    /**
     * 会话文件目录（不存在时自动创建）
     */
    public function getPath(): string
    {
        return $this->path;
    }

    private function ensureDirectory(): void
    {
        if (! is_dir($this->path)) {
            @mkdir($this->path, 0755, true);
        }
    }

    private function filePath(string $sessionId): string
    {
        $this->ensureDirectory();

        return sprintf('%s/%s.sess', $this->path, md5($this->key($sessionId)));
    }
}
