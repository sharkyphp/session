<?php
/**
 * SharkyPHP Session Library
 *
 * Session 管理器
 *
 * 用法（与 Cache / Database / Redis 包保持一致）：
 *   $session = new Session(Config::get('config.session'), new Redis(Config::get('redis.default')));
 *   $session->start();
 *   $session->set('uid', 1);
 *   $session->get('uid');
 *
 * 数据在内存中操作，析构时（或显式 save()）写回存储驱动。
 * 所有便捷方法统一走「默认驱动」（配置项 driver，缺省 file）。
 *
 * @package SharkyPHP\\Session
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @version 2.0.0
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Session;

use RuntimeException;
use Sharky\Session\Contracts\SessionDriverInterface;
use Sharky\Redis\Redis as RedisManager;
use Sharky\Session\Factory\DriverFactory;

final class Session
{
    /**
     * @var array<string, SessionDriverInterface>
     */
    private array $drivers = [];

    /**
     * @var array<string, mixed>
     */
    private array $config = [];

    private string $id = '';

    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    private bool $started = false;

    /** 数据是否有改动（用于析构时按需回写） */
    private bool $dirty = false;

    /**
     * @param array<string, mixed> $config 形如 configs/config.php 中 'session' 节点的内容
     * @param RedisManager|null    $redis  redis 子包管理器，使用 redis 驱动时必传
     */
    public function __construct(array $config = [], private ?RedisManager $redis = null)
    {
        $this->config = $config;
    }

    // ==================================================================
    // 驱动管理
    // ==================================================================

    public function driver(string $driver): SessionDriverInterface
    {
        if (array_key_exists($driver, $this->drivers)) {
            return $this->drivers[$driver];
        }

        return $this->drivers[$driver] = DriverFactory::make($driver, $this->config, $this->redis);
    }

    /**
     * @return string[]
     */
    public function getDriverNames(): array
    {
        return array_keys($this->drivers);
    }

    private function defaultDriver(): SessionDriverInterface
    {
        $driver = (string) ($this->config['driver'] ?? 'file');

        if ($driver === '') {
            throw new RuntimeException('Session driver is not configured');
        }

        return $this->driver($driver);
    }

    // ==================================================================
    // 生命周期
    // ==================================================================

    /**
     * 启动会话：解析/生成 session id，并从存储驱动读入数据
     */
    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->id      = $this->resolveId();
        $this->data    = $this->defaultDriver()->read($this->id);
        $this->started = true;
        $this->dirty   = false;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function getId(): string
    {
        $this->start();

        return $this->id;
    }

    /**
     * 重新生成 session id（登录态切换等场景防会话固定）
     */
    public function regenerate(bool $deleteOld = true): string
    {
        $this->start();

        if ($deleteOld) {
            $this->defaultDriver()->destroy($this->id);
        }

        $this->id    = $this->generateId();
        $this->dirty = true;

        $this->sendCookie($this->id);

        return $this->id;
    }

    /**
     * 把当前数据写回存储驱动
     */
    public function save(): bool
    {
        if (! $this->started || ! $this->dirty) {
            return false;
        }

        $ok = $this->defaultDriver()->write($this->id, $this->data, $this->ttl());

        if ($ok) {
            $this->dirty = false;
        }

        return $ok;
    }

    /**
     * 销毁当前会话
     */
    public function destroy(): void
    {
        if (! $this->started) {
            return;
        }

        $this->defaultDriver()->destroy($this->id);
        $this->data    = [];
        $this->started = false;
        $this->dirty   = false;
    }

    /**
     * 回收过期会话
     */
    public function gc(): int
    {
        return $this->defaultDriver()->gc($this->ttl());
    }

    // ==================================================================
    // 数据操作
    // ==================================================================

    public function set(string $key, mixed $value): void
    {
        $this->start();

        $this->data[$key] = $value;
        $this->dirty      = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();

        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        $this->start();

        return array_key_exists($key, $this->data);
    }

    public function delete(string $key): void
    {
        $this->start();

        if (array_key_exists($key, $this->data)) {
            unset($this->data[$key]);
            $this->dirty = true;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $this->start();

        return $this->data;
    }

    /**
     * 清空当前会话的数据（保留 session id）
     */
    public function clear(): void
    {
        $this->start();

        if ($this->data !== []) {
            $this->data  = [];
            $this->dirty = true;
        }
    }

    // ==================================================================
    // 内部实现
    // ==================================================================

    /**
     * 优先取 cookie 中的 id，非法或缺失则生成新的
     */
    private function resolveId(): string
    {
        $name = $this->cookieName();
        $id   = '';

        // 用 isset 取值，避免 CLI 下 $_COOKIE 未定义时产生警告
        if (isset($_COOKIE[$name]) && is_string($_COOKIE[$name])) {
            $id = $_COOKIE[$name];
        }

        if ($this->isValidId($id)) {
            return $id;
        }

        $id = $this->generateId();

        // 此时 $this->id 还没赋值，必须显式把新 id 传给下发方法
        $this->sendCookie($id);

        return $id;
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', $id);
    }

    private function sendCookie(string $id): void
    {
        // CLI 或未发送 header 的场景下不下发 cookie
        if (PHP_SAPI === 'cli' || headers_sent() || $id === '') {
            return;
        }

        $secure = (bool) ($this->config['secure'] ?? false);

        // 注意：config.path 是会话文件的存储目录，不能拿来当 cookie 的 path，
        // 否则浏览器会拿到一条 path 非法的 cookie（立即被丢弃），会话永远不连续。
        setcookie(
            $this->cookieName(),
            $id,
            [
                'expires'  => time() + $this->ttl(),
                'path'     => (string) ($this->config['cookie_path'] ?? '/'),
                'httponly' => true,
                'secure'   => $secure,
                'samesite' => 'Lax',
            ]
        );
    }

    private function cookieName(): string
    {
        return (string) ($this->config['cookie'] ?? 'SHARKY_SESSION');
    }

    private function ttl(): int
    {
        return (int) ($this->config['ttl'] ?? 7200);
    }

    public function __destruct()
    {
        // 请求结束时按需回写，避免每次 set 都写存储
        try {
            $this->save();
        } catch (\Throwable) {
            // 析构阶段异常不影响主流程
        }
    }
}
