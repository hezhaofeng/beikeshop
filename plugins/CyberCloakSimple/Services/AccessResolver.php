<?php

namespace Plugin\CyberCloakSimple\Services;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * 按 IP、key、签名 Cookie、地区和浏览器语言确定真实或展示模式。
 */
class AccessResolver
{
    /**
     * 注入访问凭据和动态配置服务。
     */
    public function __construct(
        private readonly AccessKeyService $accessKeys,
        private readonly ContextTicketService $tickets,
        private readonly SettingService $settings,
        private readonly CountryResolver $countries,
    ) {
    }

    /**
     * 解析一次前台请求，并移除 URL 中的 key，防止其进入站内链接和分页地址。
     *
     * @return array{mode:string,reason:string,blocked:bool,cookie:array<string,mixed>}
     */
    public function resolve(Request $request): array
    {
        $cookieName  = (string) $this->settings->value('cookie_name', config('cyber_cloak_simple.cookie_name'));
        $cookieValue = $request->cookie($cookieName);
        $hasCookie   = is_string($cookieValue) && $cookieValue !== '';
        $keyName     = (string) $this->settings->value('key_parameter', config('cyber_cloak_simple.key_parameter', 'key'));
        $queryValue  = $keyName === '' ? '' : $request->query($keyName, '');
        $queryKey    = is_scalar($queryValue) ? trim((string) $queryValue) : '';
        if ($keyName !== '') {
            $request->query->remove($keyName);
        }

        $clientIp = (string) ($request->ip() ?: $request->server('REMOTE_ADDR') ?: '0.0.0.0');
        if ($this->matchesIpList($clientIp, $this->settings->list('ip_blacklist'))) {
            return $this->publicResolution('ip_blacklist', $hasCookie);
        }

        $ipWhitelist = $this->settings->list('ip_whitelist');
        if ($ipWhitelist !== [] && ! $this->matchesIpList($clientIp, $ipWhitelist)) {
            return $this->publicResolution('ip_not_whitelisted', $hasCookie);
        }

        // 国家/地区白名单按客户端 IP 先行校验，真实 key 和签名 Cookie 也必须满足地域限制。
        if (! $this->countryAllowed($request)) {
            return $this->publicResolution('country_not_whitelisted', $hasCookie, true);
        }

        if ($queryKey !== '') {
            $record = $this->accessKeys->findValid($queryKey);
            if ($record) {
                return $this->realResolution($record, 'query_key');
            }
        }

        $ticket = $this->tickets->verify($hasCookie ? $cookieValue : null);
        if ($ticket) {
            return $this->realResolution($ticket['record'], 'cookie');
        }

        // 浏览器语言仅控制无凭据的展示访问；已验证 key/Cookie 保持真实模式。
        if (! $this->languageAllowed($request)) {
            return $this->publicResolution('language_not_whitelisted', $hasCookie, true);
        }

        return $this->publicResolution($queryKey === '' ? 'no_credentials' : 'invalid_key', $hasCookie);
    }

    /**
     * IP 黑白名单支持 IPv4、IPv6 和 CIDR；无效规则只忽略本行。
     *
     * @param array<int,string> $ranges
     */
    private function matchesIpList(string $ip, array $ranges): bool
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
                // 配置中的坏网段不能使全部请求失败。
            }
        }

        return false;
    }

    /**
     * 对比客户端 IP 解析出的地区代码；空白名单表示不限制地区。
     */
    private function countryAllowed(Request $request): bool
    {
        $allowed = array_map('strtoupper', $this->settings->list('country_whitelist'));
        if ($allowed === []) {
            return true;
        }

        $country = $this->countries->resolve($request);

        return $country !== null && in_array($country, $allowed, true);
    }

    /**
     * 对比 Accept-Language；en 与 en-US、zh 与 zh-CN 均视为同一基础语言。
     */
    private function languageAllowed(Request $request): bool
    {
        $allowed = $this->settings->list('language_whitelist');
        if ($allowed === []) {
            return true;
        }

        $requested = [];
        foreach (explode(',', (string) $request->header('Accept-Language', '')) as $item) {
            $language = trim(explode(';', $item, 2)[0]);
            if ($language !== '') {
                $requested[] = $language;
            }
        }

        foreach ($requested as $candidate) {
            foreach ($allowed as $expected) {
                if ($this->sameLanguage($candidate, $expected)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 规范化比较完整语言标签和基础语言标签。
     */
    private function sameLanguage(string $left, string $right): bool
    {
        $left  = strtoupper(str_replace('_', '-', trim($left)));
        $right = strtoupper(str_replace('_', '-', trim($right)));
        if ($left === '' || $right === '') {
            return false;
        }

        return $left === $right || strtok($left, '-') === strtok($right, '-');
    }

    /**
     * 创建真实模式结果，并将 key 替换为签名 Cookie。
     *
     * @return array{mode:string,reason:string,blocked:bool,cookie:array<string,mixed>}
     */
    private function realResolution(array $record, string $reason): array
    {
        return [
            'mode'    => StoreContext::REAL,
            'reason'  => $reason,
            'blocked' => false,
            'cookie'  => [
                'action'  => 'set',
                'value'   => $this->tickets->issue($record),
                'minutes' => $this->cookieLifetime($this->accessKeys->expiresAtTimestamp($record)),
            ],
        ];
    }

    /**
     * 创建展示模式结果，必要时清除失效或已被规则拒绝的上下文 Cookie。
     *
     * @return array{mode:string,reason:string,blocked:bool,cookie:array<string,mixed>}
     */
    private function publicResolution(string $reason, bool $hasCookie, bool $blocked = false): array
    {
        return [
            'mode'    => StoreContext::PUBLIC,
            'reason'  => $reason,
            'blocked' => $blocked,
            'cookie'  => ['action' => $hasCookie ? 'forget' : 'none'],
        ];
    }

    /**
     * Cookie 浏览器有效期不能超过服务端 key 的有效期。
     */
    private function cookieLifetime(int $keyExpiresAt): int
    {
        $configured = max(0, (int) $this->settings->value('cookie_lifetime_minutes', config('cyber_cloak_simple.cookie_lifetime_minutes', 43200)));
        if ($keyExpiresAt === 0) {
            return $configured;
        }

        $remaining = max(1, (int) ceil(($keyExpiresAt - time()) / 60));

        return $configured === 0 ? $remaining : min($configured, $remaining);
    }
}
