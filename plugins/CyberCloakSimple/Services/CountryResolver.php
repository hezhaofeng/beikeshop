<?php

namespace Plugin\CyberCloakSimple\Services;

use GeoIp2\Database\Reader;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * 依据客户端 IP 解析国家/地区；可信反向代理请求头仅作为没有 GeoIP 数据库时的回退。
 */
class CountryResolver
{
    /**
     * 注入动态设置读取服务。
     */
    public function __construct(private readonly SettingService $settings)
    {
    }

    /**
     * 返回当前客户端 IP 对应的 ISO 3166-1 alpha-2 国家/地区代码。
     */
    public function resolve(Request $request): ?string
    {
        $ip      = (string) ($request->ip() ?: $request->server('REMOTE_ADDR') ?: '');
        $database = trim((string) $this->settings->value(
            'geoip_country_database',
            config('cyber_cloak_simple.geoip_country_database', '')
        ));
        if ($database !== '' && is_file($database) && class_exists(Reader::class)) {
            try {
                $country = (new Reader($database))->country($ip)->country->isoCode;
                $country = strtoupper(trim((string) $country));
                if (preg_match('/^[A-Z]{2}$/', $country) === 1) {
                    return $country;
                }
            } catch (\Throwable) {
                // 私有地址、无匹配记录或损坏数据库时继续使用可信请求头回退。
            }
        }

        $header = trim((string) $this->settings->value('country_header', config('cyber_cloak_simple.country_header', 'CF-IPCountry')));
        if (! $this->isTrustedProxy($request) || preg_match('/^[A-Za-z0-9-]{1,128}$/', $header) !== 1) {
            return null;
        }
        $country = strtoupper(trim((string) $request->header($header, '')));

        return preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;
    }

    /**
     * 仅接受已配置反向代理写入的地区头，避免直连源站时由客户端伪造。
     */
    private function isTrustedProxy(Request $request): bool
    {
        $remoteAddress = trim((string) $request->server('REMOTE_ADDR', ''));
        if (! filter_var($remoteAddress, FILTER_VALIDATE_IP)) {
            return false;
        }

        foreach ($this->settings->list('trusted_proxy_ips') as $range) {
            try {
                if (IpUtils::checkIp($remoteAddress, $range)) {
                    return true;
                }
            } catch (\Throwable) {
                // 单条代理网段格式错误时忽略，不扩大可信来源范围。
            }
        }

        return false;
    }
}
