<?php

namespace Plugin\CyberCloak\Services;

use Beike\Models\Setting;
use GeoIp2\Database\Reader;

class MaxMindIpIntelligence
{
    /** @var array<string,Reader> */
    private array $readers = [];

    /** @var array<string,mixed> */
    private array $settingCache = [];

    private int $settingsLoadedAt = 0;

    /**
     * 使用本地 MaxMind MMDB 查询国家、ASN 和组织信息。
     *
     * GeoLite 数据库只负责地理和 ASN 信号，信誉及代理判断由其他信号提供。
     * 数据库不存在或记录缺失时返回 unknown，避免一次 GeoIP 故障阻断前台请求。
     *
     * @return array{country:?string,asn:?int,organization:?string,anonymous:?bool,available:bool,source:string}
     */
    public function lookup(string $ip): array
    {
        $result = [
            'country'      => null,
            'asn'          => null,
            'organization' => null,
            'anonymous'    => null,
            'available'    => false,
            'source'       => 'none',
        ];

        if (! filter_var($ip, FILTER_VALIDATE_IP) || ! $this->enabled()) {
            return $result;
        }

        $countryPath = (string) $this->setting('geoip_country_database', '');
        if ($countryPath !== '' && is_file($countryPath)) {
            try {
                $country             = $this->reader('country', $countryPath)->country($ip);
                $result['country']   = $country->country->isoCode ?: null;
                $result['available'] = true;
                $result['source']    = 'maxmind';
            } catch (\Throwable) {
                // 单条地址解析失败只保留其他信号，不能影响整条请求。
            }
        }

        $asnPath = (string) $this->setting('geoip_asn_database', '');
        if ($asnPath !== '' && is_file($asnPath)) {
            try {
                $asn                    = $this->reader('asn', $asnPath)->asn($ip);
                $result['asn']          = $asn->autonomousSystemNumber ?: null;
                $result['organization'] = $asn->autonomousSystemOrganization ?: null;
                $result['available']    = true;
                $result['source']       = 'maxmind';
            } catch (\Throwable) {
                // ASN 数据库可选，缺失时继续使用国家和基础规则。
            }
        }

        $anonymousPath = (string) $this->setting('geoip_anonymous_database', '');
        if ($anonymousPath !== '' && is_file($anonymousPath)) {
            try {
                $reader = $this->reader('anonymous', $anonymousPath);
                if (method_exists($reader, 'anonymousIp')) {
                    $anonymous           = $reader->anonymousIp($ip);
                    $result['anonymous'] = (bool) ($anonymous->isAnonymous ?? false);
                    $result['available'] = true;
                    $result['source']    = 'maxmind';
                }
            } catch (\Throwable) {
                // GeoLite 没有匿名代理数据时保持 unknown，不把 unknown 当作低风险。
            }
        }

        return $result;
    }

    /**
     * 延迟创建并复用 MMDB Reader，避免同一请求重复打开数据库文件。
     */
    private function reader(string $type, string $path): Reader
    {
        $key = $type . ':' . $path;
        if (! isset($this->readers[$key])) {
            $this->readers[$key] = new Reader($path);
        }

        return $this->readers[$key];
    }

    /**
     * 读取 GeoIP 开关；数据库路径属于部署配置，避免每次请求查询 settings 表。
     */
    private function enabled(): bool
    {
        return filter_var($this->setting('geoip_enabled', true), FILTER_VALIDATE_BOOL);
    }

    /**
     * 读取部署级 GeoIP 配置；更新路径后清理配置缓存并重启长驻进程。
     */
    private function setting(string $name, mixed $default): mixed
    {
        if (app()->environment('testing')) {
            return config("cyber_cloak.{$name}", $default);
        }

        // GeoIP 路径属于低频部署设置，短缓存兼顾后台修改生效和请求性能。
        if ($this->settingsLoadedAt === 0 || (time() - $this->settingsLoadedAt) >= 60) {
            $this->settingsLoadedAt = time();

            try {
                Setting::query()
                    ->where('type', 'plugin')
                    ->where('space', 'cyber_cloak')
                    ->whereIn('name', ['geoip_enabled', 'geoip_country_database', 'geoip_asn_database', 'geoip_anonymous_database'])
                    ->get()
                    ->each(function ($setting): void {
                        $this->settingCache[$setting->name] = $setting->json
                            ? json_decode((string) $setting->value, true)
                            : $setting->value;
                    });
            } catch (\Throwable) {
                // settings 表尚未建立时继续使用环境变量配置。
            }
        }

        return array_key_exists($name, $this->settingCache)
            ? $this->settingCache[$name]
            : config("cyber_cloak.{$name}", $default);
    }
}
