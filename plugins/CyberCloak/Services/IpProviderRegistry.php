<?php

namespace Plugin\CyberCloak\Services;

use InvalidArgumentException;

class IpProviderRegistry
{
    /**
     * @var array<string,IpProviderInterface>
     */
    private array $providers;

    public function __construct()
    {
        $this->providers = ['http' => new HttpIpProvider];
    }

    /**
     * 注册供应商适配器，供其他插件在 Bootstrap 中扩展。
     */
    public function register(string $code, IpProviderInterface $provider): void
    {
        $code = trim($code);
        if ($code === '') {
            throw new InvalidArgumentException('IP 供应商编码不能为空');
        }
        $this->providers[$code] = $provider;
    }

    /**
     * 获取已注册的供应商适配器。
     */
    public function resolve(string $code): IpProviderInterface
    {
        if (! isset($this->providers[$code])) {
            throw new InvalidArgumentException("IP 供应商适配器 {$code} 未注册");
        }

        return $this->providers[$code];
    }

    /**
     * 返回后台可展示的适配器编码列表。
     *
     * @return array<int,string>
     */
    public function codes(): array
    {
        return array_keys($this->providers);
    }
}
