<?php

// HTTP 站点不能默认下发 Secure Cookie，否则浏览器不会在后续页面回传上下文。
$cookieSecure = env('CYBER_CLOAK_SIMPLE_COOKIE_SECURE');
if ($cookieSecure === null || $cookieSecure === '') {
    $cookieSecure = str_starts_with(strtolower((string) config('app.url', '')), 'https://');
}

return [
    // 首次访问携带有效 key 后转为签名 Cookie，Cookie 不保存明文 key。
    'key_parameter'            => env('CYBER_CLOAK_SIMPLE_KEY_PARAMETER', 'key'),
    'cookie_name'              => env('CYBER_CLOAK_SIMPLE_COOKIE_NAME', 'beike_simple_context'),
    'cookie_lifetime_minutes'  => (int) env('CYBER_CLOAK_SIMPLE_COOKIE_LIFETIME', 43200),
    'cookie_secure'            => filter_var($cookieSecure, FILTER_VALIDATE_BOOL),
    'cookie_http_only'         => true,
    'cookie_same_site'         => env('CYBER_CLOAK_SIMPLE_COOKIE_SAME_SITE', 'lax'),
    'cookie_path'              => env('CYBER_CLOAK_SIMPLE_COOKIE_PATH', '/'),
    'prevent_shared_cache'     => filter_var(env('CYBER_CLOAK_SIMPLE_PREVENT_SHARED_CACHE', true), FILTER_VALIDATE_BOOL),

    // 环境变量可用于首次部署；后台保存后由插件 settings 覆盖。
    'access_keys'         => json_decode(env('CYBER_CLOAK_SIMPLE_ACCESS_KEYS', '[]'), true) ?: [],
    'ip_whitelist'        => env('CYBER_CLOAK_SIMPLE_IP_WHITELIST', ''),
    'ip_blacklist'        => env('CYBER_CLOAK_SIMPLE_IP_BLACKLIST', ''),
    'country_header'      => env('CYBER_CLOAK_SIMPLE_COUNTRY_HEADER', 'CF-IPCountry'),
    'trusted_proxy_ips'   => env('CYBER_CLOAK_SIMPLE_TRUSTED_PROXY_IPS', ''),
    'geoip_country_database' => env('CYBER_CLOAK_SIMPLE_GEOIP_COUNTRY_DATABASE', ''),
    'country_whitelist'   => env('CYBER_CLOAK_SIMPLE_COUNTRY_WHITELIST', ''),
    'language_whitelist'  => env('CYBER_CLOAK_SIMPLE_LANGUAGE_WHITELIST', ''),
    'product_mappings'    => json_decode(env('CYBER_CLOAK_SIMPLE_PRODUCT_MAPPINGS', '[]'), true) ?: [],
    'category_mappings'   => json_decode(env('CYBER_CLOAK_SIMPLE_CATEGORY_MAPPINGS', '[]'), true) ?: [],
];
