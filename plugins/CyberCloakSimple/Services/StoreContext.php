<?php

namespace Plugin\CyberCloakSimple\Services;

/**
 * 保存当前前台请求的访问模式，避免常驻进程复用上一个请求的状态。
 */
class StoreContext
{
    public const REAL = 'real';

    public const PUBLIC = 'public';

    private string $mode = self::PUBLIC;

    private string $reason = 'default';

    private bool $active = false;

    /**
     * 激活当前请求的访问模式。
     *
     * @param array{mode?:string,reason?:string} $resolution
     */
    public function activate(array $resolution): void
    {
        $mode         = (string) ($resolution['mode'] ?? self::PUBLIC);
        $this->mode    = in_array($mode, [self::REAL, self::PUBLIC], true) ? $mode : self::PUBLIC;
        $this->reason  = (string) ($resolution['reason'] ?? 'default');
        $this->active  = true;
    }

    /**
     * 在响应结束后恢复默认状态。
     */
    public function reset(): void
    {
        $this->mode   = self::PUBLIC;
        $this->reason = 'default';
        $this->active = false;
    }

    /**
     * 判断当前是否是前台已激活的真实模式。
     */
    public function isReal(): bool
    {
        return $this->active && $this->mode === self::REAL;
    }

    /**
     * 判断当前是否是前台已激活的展示模式。
     */
    public function isPublic(): bool
    {
        return $this->active && $this->mode === self::PUBLIC;
    }

    /**
     * 返回当前请求的判定原因，供日志和测试诊断使用。
     */
    public function reason(): string
    {
        return $this->reason;
    }
}
