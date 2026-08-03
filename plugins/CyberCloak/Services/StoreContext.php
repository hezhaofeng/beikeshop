<?php

namespace Plugin\CyberCloak\Services;

class StoreContext
{
    public const REAL = 'real';

    public const PUBLIC = 'public';

    private string $mode = self::PUBLIC;

    private ?string $keyId = null;

    private ?string $clientIp = null;

    private string $reason = 'default';

    /**
     * 标记当前进程是否正在处理前台商品上下文请求。
     */
    private bool $active = false;

    /**
     * 激活当前请求的商品库上下文。
     *
     * @param array{mode?: string, key_id?: string|null, client_ip?: string|null, reason?: string} $resolution
     */
    public function activate(array $resolution): void
    {
        $mode           = $resolution['mode'] ?? self::PUBLIC;
        $this->mode     = in_array($mode, [self::REAL, self::PUBLIC], true) ? $mode : self::PUBLIC;
        $this->keyId    = $resolution['key_id']       ?? null;
        $this->clientIp = $resolution['client_ip']    ?? null;
        $this->reason   = (string) ($resolution['reason'] ?? 'default');
        $this->active   = true;
    }

    /**
     * 恢复默认展示模式，避免常驻进程复用上一次请求的状态。
     */
    public function reset(): void
    {
        $this->mode     = self::PUBLIC;
        $this->keyId    = null;
        $this->clientIp = null;
        $this->reason   = 'default';
        $this->active   = false;
    }

    /**
     * 获取当前模式。
     */
    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * 判断当前请求是否使用真实商品库。
     */
    public function isReal(): bool
    {
        return $this->mode === self::REAL;
    }

    /**
     * 判断当前请求是否使用展示商品库。
     */
    public function isPublic(): bool
    {
        return $this->mode === self::PUBLIC;
    }

    /**
     * 判断当前连接切换是否由前台请求显式激活。
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * 获取触发真实模式的 key 标识。
     */
    public function keyId(): ?string
    {
        return $this->keyId;
    }

    /**
     * 获取经过代理解析后的客户端 IP。
     */
    public function clientIp(): ?string
    {
        return $this->clientIp;
    }

    /**
     * 获取上下文判定原因，便于日志和后续后台诊断。
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * 根据当前模式返回后续仓储应使用的数据库连接名。
     */
    public function connectionName(): string
    {
        $mode = $this->active ? $this->mode : self::REAL;

        return (string) config('cyber_cloak.connections.' . $mode, 'mysql');
    }
}
