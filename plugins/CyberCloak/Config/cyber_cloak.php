<?php

// 默认识别各平台的专用抓取客户端，具体标记排在通用前缀前以保留准确审计信号。
$defaultTrafficUserAgentBlacklist = implode("\n", [
    'Googlebot',
    'AdsBot-Google-Mobile',
    'AdsBot-Google',
    'Mediapartners-Google',
    'Storebot-Google',
    'Google-InspectionTool',
    'GoogleOther',
    'Google-Extended',
    'facebookexternalhit',
    'facebookcatalog',
    'Facebot',
    'meta-externalagent',
    'meta-externalfetcher',
    'InstagramBot',
]);

// 未显式指定时跟随站点协议：HTTP 站点不能下发 Secure Cookie，否则后续页面会丢失真实站上下文。
$cookieSecure = env('CYBER_CLOAK_COOKIE_SECURE');
if ($cookieSecure === null || $cookieSecure === '') {
    $cookieSecure = str_starts_with(strtolower((string) config('app.url', '')), 'https://');
}

return [
    // 有效 key 通过 query 参数首次进入真实模式，后续由签名 Cookie 维持。
    'key_parameter' => env('CYBER_CLOAK_KEY_PARAMETER', 'key'),

    // Cookie 不包含明文 key，只保存带签名的上下文票据。
    'cookie_name'              => env('CYBER_CLOAK_COOKIE_NAME', 'beike_context'),
    'cookie_lifetime_minutes'  => (int) env('CYBER_CLOAK_COOKIE_LIFETIME', 43200),
    'cookie_refresh_minutes'   => (int) env('CYBER_CLOAK_COOKIE_REFRESH', 1440),
    'cookie_secure'            => filter_var($cookieSecure, FILTER_VALIDATE_BOOL),
    'cookie_http_only'         => true,
    'cookie_same_site'         => env('CYBER_CLOAK_COOKIE_SAME_SITE', 'lax'),
    'cookie_path'              => env('CYBER_CLOAK_COOKIE_PATH', '/'),
    'prevent_shared_cache'     => filter_var(env('CYBER_CLOAK_PREVENT_SHARED_CACHE', true), FILTER_VALIDATE_BOOL),

    // 可通过插件后台设置覆盖；环境变量用于部署时初始化和自动化测试。
    'access_keys'        => json_decode(env('CYBER_CLOAK_ACCESS_KEYS', '[]'), true) ?: [],
    'ip_whitelist'       => env('CYBER_CLOAK_IP_WHITELIST', ''),
    'ip_blacklist'       => env('CYBER_CLOAK_IP_BLACKLIST', ''),
    'ip_reputation'      => env('CYBER_CLOAK_IP_REPUTATION', ''),
    'trusted_proxy_ips'  => env('CYBER_CLOAK_TRUSTED_PROXY_IPS', ''),
    'cloudflare_enabled' => filter_var(env('CYBER_CLOAK_CLOUDFLARE_ENABLED', true), FILTER_VALIDATE_BOOL),

    // 供应商只在后台测试或定时命令中访问，前台永远读取本地缓存。
    'ip_provider_enabled'     => filter_var(env('CYBER_CLOAK_IP_PROVIDER_ENABLED', false), FILTER_VALIDATE_BOOL),
    'ip_provider'             => env('CYBER_CLOAK_IP_PROVIDER', 'http'),
    'ip_provider_endpoint'    => env('CYBER_CLOAK_IP_PROVIDER_ENDPOINT', ''),
    'ip_provider_token'       => env('CYBER_CLOAK_IP_PROVIDER_TOKEN', ''),
    'ip_provider_timeout'     => (int) env('CYBER_CLOAK_IP_PROVIDER_TIMEOUT', 5),
    'ip_provider_cache_ttl'   => (int) env('CYBER_CLOAK_IP_PROVIDER_CACHE_TTL', 60),
    'ip_provider_fail_mode'   => env('CYBER_CLOAK_IP_PROVIDER_FAIL_MODE', 'public'),
    'ip_provider_allow_empty' => filter_var(env('CYBER_CLOAK_IP_PROVIDER_ALLOW_EMPTY', false), FILTER_VALIDATE_BOOL),
    'ip_provider_schedule'    => env('CYBER_CLOAK_IP_PROVIDER_SCHEDULE', 'hourly'),

    // 四层流量漏斗配置；GeoLite/GeoIP 数据库路径只在服务端读取，前台不会请求远程 API。
    'geoip_enabled'                => filter_var(env('CYBER_CLOAK_GEOIP_ENABLED', true), FILTER_VALIDATE_BOOL),
    'geoip_country_database'       => env('CYBER_CLOAK_GEOIP_COUNTRY_DATABASE', ''),
    'geoip_asn_database'           => env('CYBER_CLOAK_GEOIP_ASN_DATABASE', ''),
    'geoip_anonymous_database'     => env('CYBER_CLOAK_GEOIP_ANONYMOUS_DATABASE', ''),
    'traffic_funnel_enabled'       => filter_var(env('CYBER_CLOAK_TRAFFIC_FUNNEL_ENABLED', true), FILTER_VALIDATE_BOOL),
    'traffic_allowed_countries'    => env('CYBER_CLOAK_TRAFFIC_ALLOWED_COUNTRIES', ''),
    'traffic_blocked_countries'    => env('CYBER_CLOAK_TRAFFIC_BLOCKED_COUNTRIES', ''),
    'traffic_allowed_languages'    => env('CYBER_CLOAK_TRAFFIC_ALLOWED_LANGUAGES', ''),
    'traffic_user_agent_blacklist' => env('CYBER_CLOAK_TRAFFIC_USER_AGENT_BLACKLIST', $defaultTrafficUserAgentBlacklist),
    'traffic_blocked_asns'         => env('CYBER_CLOAK_TRAFFIC_BLOCKED_ASNS', ''),
    'traffic_datacenter_asns'      => env('CYBER_CLOAK_TRAFFIC_DATACENTER_ASNS', ''),
    'traffic_rate_limit_enabled'   => filter_var(env('CYBER_CLOAK_TRAFFIC_RATE_LIMIT_ENABLED', false), FILTER_VALIDATE_BOOL),
    'traffic_rate_limit_max'       => (int) env('CYBER_CLOAK_TRAFFIC_RATE_LIMIT_MAX', 120),
    'traffic_rate_limit_decay'     => (int) env('CYBER_CLOAK_TRAFFIC_RATE_LIMIT_DECAY', 60),
    'traffic_block_threshold'      => (int) env('CYBER_CLOAK_TRAFFIC_BLOCK_THRESHOLD', 80),
    'traffic_challenge_threshold'  => (int) env('CYBER_CLOAK_TRAFFIC_CHALLENGE_THRESHOLD', 50),
    'traffic_fingerprint_cookie'   => env('CYBER_CLOAK_TRAFFIC_FINGERPRINT_COOKIE', ''),

    // 阶段一不修改默认连接，后续商品仓储通过此映射显式选择连接。
    'connections' => [
        'real'   => 'mysql',
        'public' => 'catalog_public',
    ],
];
