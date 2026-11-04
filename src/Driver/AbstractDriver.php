<?php
/**
 * SharkyPHP Session Library
 *
 * 会话存储驱动公共基类：负责键前缀与序列化
 *
 * @package SharkyPHP\\Session
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Session\Driver;

use Sharky\Session\Contracts\SessionDriverInterface;

abstract class AbstractDriver implements SessionDriverInterface
{
    protected string $prefix;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(protected array $config = [])
    {
        $this->prefix = (string) ($config['prefix'] ?? '');
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * 覆盖键前缀（返回自身，支持链式）
     */
    public function prefix(string $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    protected function key(string $sessionId): string
    {
        return $this->prefix . $sessionId;
    }

    /**
     * 序列化会话数据
     */
    protected function serialize(array $data): string
    {
        return serialize($data);
    }

    /**
     * 反序列化会话数据
     *
     * 禁用 allowed_classes，避免反序列化意外还原对象（对象注入防护）
     */
    protected function unserialize(string $raw): mixed
    {
        return unserialize($raw, ['allowed_classes' => false]);
    }
}
