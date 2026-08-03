<?php

namespace Plugin\CyberCloak\Services;

use Beike\Models\Setting;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

class IpAccessService
{
    /**
     * 供应商同步服务只负责读取本地缓存，避免在前台请求中触发远程网络调用。
     */
    public function __construct(private readonly ?IpProviderSyncService $providerSync = null)
    {
    }

    /**
     * 解析客户端 IP，并按黑名单优先、白名单其次的规则判断是否允许真实模式。
     *
     * @return array{ip: string, allowed: bool, blacklisted: bool, whitelisted: bool, reputation: bool, provider_unavailable: bool}
     */
    public function evaluate(Request $request): array
    {
        $ip                  = $this->resolveClientIp($request);
        $providerUnavailable = $this->provider()->isUnavailable();
        $blacklisted         = $this->matchesAny($ip, array_merge(
            $this->configuredList('ip_blacklist'),
            $this->provider()->cachedRanges()
        ));
        if ($providerUnavailable) {
            // 供应商缓存失效时按 fail_mode=public 处理，黑名单优先级仍保持不变。
            $blacklisted = true;
        }
        $whitelist   = $this->configuredList('ip_whitelist');
        $whitelisted = $this->matchesAny($ip, $whitelist);
        $reputation  = $this->matchesAny($ip, $this->configuredList('ip_reputation'));

        return [
            'ip'                   => $ip,
            'allowed'              => ! $blacklisted && ($whitelist === [] || $whitelisted),
            'blacklisted'          => $blacklisted,
            'whitelisted'          => $whitelisted,
            'reputation'           => $reputation,
            'provider_unavailable' => $providerUnavailable,
        ];
    }

    /**
     * 从 Cloudflare 可信代理头或 Laravel 已解析的客户端地址获取 IP。
     */
    public function resolveClientIp(Request $request): string
    {
        $remoteAddress = (string) ($request->server('REMOTE_ADDR') ?: '');
        $trustedProxy  = $this->matchesAny($remoteAddress, $this->configuredList('trusted_proxy_ips'));

        if ($trustedProxy && config('cyber_cloak.cloudflare_enabled', true)) {
            $cloudflareIp = trim((string) $request->header('CF-Connecting-IP', ''));
            if (filter_var($cloudflareIp, FILTER_VALIDATE_IP)) {
                return $cloudflareIp;
            }
        }

        return (string) ($request->ip() ?: $remoteAddress ?: '0.0.0.0');
    }

    /**
     * 读取插件设置中的 IP/CIDR 列表，支持换行、逗号和 JSON 数组。
     *
     * @return array<int, string>
     */
    private function configuredList(string $name): array
    {
        // IP 规则同样需要按请求读取数据库，确保后台修改在常驻进程中即时生效。
        $value = null;

        try {
            $setting = Setting::query()
                ->where('type', 'plugin')
                ->where('space', 'cyber_cloak')
                ->where('name', $name)
                ->first();
            if ($setting) {
                $value = $setting->json ? json_decode((string) $setting->value, true) : $setting->value;
            }
        } catch (\Throwable) {
            // settings 表尚未就绪时继续使用配置文件中的测试/部署值。
        }
        if ($value === null || $value === '') {
            $value = function_exists('plugin_setting') ? plugin_setting("cyber_cloak.{$name}", null) : null;
        }
        if ($value === null || $value === '') {
            $value = config("cyber_cloak.{$name}", '');
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value   = is_array($decoded) ? $decoded : preg_split('/[\\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($item) => is_string($item) ? trim($item) : '',
            $value
        )));
    }

    /**
     * 判断 IP 是否命中任意精确地址或 CIDR 网段。
     */
    private function matchesAny(string $ip, array $ranges): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        foreach ($ranges as $range) {
            try {
                if (IpUtils::checkIp($ip, $range)) {
                    return true;
                }
            } catch (\Throwable) {
                // 忽略无效网段，避免一条错误配置阻断全部前台请求。
            }
        }

        return false;
    }

    /**
     * 获取可选的供应商服务，保证旧版本未注册阶段六服务时仍可执行基础 IP 判断。
     */
    private function provider(): IpProviderSyncService
    {
        return $this->providerSync ?: (app()->bound(IpProviderSyncService::class)
            ? app(IpProviderSyncService::class)
            : new IpProviderSyncService(new IpProviderRegistry, new IpRangeNormalizer));
    }
}
