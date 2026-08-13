<?php

use Beike\Models\Country;

// 优先读取店铺已维护的国家表，安装早期仍提供常用国家选项。
$countryOptions = [
    ['value' => 'CN', 'label' => 'CN - China'],
    ['value' => 'US', 'label' => 'US - United States'],
    ['value' => 'GB', 'label' => 'GB - United Kingdom'],
    ['value' => 'JP', 'label' => 'JP - Japan'],
    ['value' => 'KR', 'label' => 'KR - South Korea'],
    ['value' => 'DE', 'label' => 'DE - Germany'],
    ['value' => 'FR', 'label' => 'FR - France'],
    ['value' => 'CA', 'label' => 'CA - Canada'],
    ['value' => 'AU', 'label' => 'AU - Australia'],
    ['value' => 'SG', 'label' => 'SG - Singapore'],
];

try {
    $storedCountries = Country::query()
        ->orderBy('name')
        ->get(['code', 'name'])
        ->filter(static fn (Country $country): bool => $country->code !== '')
        ->map(static fn (Country $country): array => [
            'value' => strtoupper($country->code),
            'label' => strtoupper($country->code) . ' - ' . $country->name,
        ])
        ->values()
        ->all();
    if ($storedCountries !== []) {
        $countryOptions = $storedCountries;
    }
} catch (\Throwable) {
    // 数据库尚未安装时继续使用内置国家选项。
}

$languageOptions = [
    ['value' => 'ZH-CN', 'label' => '简体中文 (zh-CN)'],
    ['value' => 'ZH-TW', 'label' => '繁体中文 (zh-TW)'],
    ['value' => 'EN', 'label' => 'English (en)'],
    ['value' => 'EN-US', 'label' => 'English, United States (en-US)'],
    ['value' => 'EN-GB', 'label' => 'English, United Kingdom (en-GB)'],
    ['value' => 'JA', 'label' => '日本語 (ja)'],
    ['value' => 'KO', 'label' => '한국어 (ko)'],
    ['value' => 'DE', 'label' => 'Deutsch (de)'],
    ['value' => 'FR', 'label' => 'Francais (fr)'],
    ['value' => 'ES', 'label' => 'Espanol (es)'],
];

return [
    [
        'name'        => 'access_keys',
        'label'       => '访问 key 列表',
        'type'        => 'textarea',
        'required'    => false,
        'description' => 'JSON 数组。每项包含 id、value、expires_at、status；value 为访问 key，运行时仅保留 SHA-256 摘要。expires_at 使用 ISO 8601 时间，留空表示长期有效。也兼容预先生成的 hash。',
        'rules'       => 'nullable|json',
    ],
    [
        'name'        => 'ip_whitelist',
        'label'       => 'IP 白名单',
        'type'        => 'textarea',
        'required'    => false,
        'description' => '每行一个 IPv4、IPv6 或 CIDR。配置后，未命中的 IP 即使携带有效 key 也只进入展示模式。',
        'rules'       => 'nullable|string',
    ],
    [
        'name'        => 'ip_blacklist',
        'label'       => 'IP 黑名单',
        'type'        => 'textarea',
        'required'    => false,
        'description' => '每行一个 IPv4、IPv6 或 CIDR。黑名单优先于 key 和 Cookie，并清除已有上下文 Cookie。',
        'rules'       => 'nullable|string',
    ],
    [
        'name'        => 'country_header',
        'label'       => '地区请求头名称',
        'type'        => 'string',
        'required'    => false,
        'description' => '默认 CF-IPCountry。当 GeoIP 数据库未配置时，使用可信反向代理写入的 ISO 3166-1 alpha-2 国家/地区代码。',
        'rules'       => 'nullable|string|max:128',
    ],
    [
        'name'        => 'trusted_proxy_ips',
        'label'       => '可信反向代理 IP/CIDR',
        'type'        => 'textarea',
        'required'    => false,
        'description' => '每行一个反向代理 IPv4、IPv6 或 CIDR。只有 REMOTE_ADDR 命中该列表时才信任 CF-IPCountry 等地区请求头；使用 Cloudflare 时填写其回源网段。',
        'rules'       => 'nullable|string',
    ],
    [
        'name'        => 'geoip_country_database',
        'label'       => '国家/地区 GeoIP 数据库路径',
        'type'        => 'string',
        'required'    => false,
        'description' => '可选的 GeoLite2-Country.mmdb 绝对路径。配置后按客户端 IP 判定国家/地区，优先级高于地区请求头。',
        'rules'       => 'nullable|string|max:2048',
    ],
    [
        'name'        => 'country_whitelist',
        'label'       => '地区白名单',
        'type'        => 'select-multiple',
        'options'     => $countryOptions,
        'required'    => false,
        'description' => '可多选。仅允许来自所选国家/地区的客户端 IP 访问；该规则先于 key 和 Cookie 校验执行，未命中返回 404。',
        'rules'       => 'nullable|array',
    ],
    [
        'name'        => 'language_whitelist',
        'label'       => '浏览器语言白名单',
        'type'        => 'select-multiple',
        'options'     => $languageOptions,
        'required'    => false,
        'description' => '可多选。仅影响无 key/Cookie 的展示访问，支持基础语言和地区语言互相匹配。',
        'rules'       => 'nullable|array',
    ],
    [
        'name'        => 'product_mappings',
        'label'       => '商品映射',
        'type'        => 'textarea',
        'required'    => false,
        'description' => 'JSON 数组，例如 [{"real_id":1,"public_id":101}]。一键处理会保留已有关系并为其余启用商品补齐同 ID 映射；首页商品模块直接复用此映射。',
        'rules'       => 'nullable|json',
    ],
    [
        'name'        => 'category_mappings',
        'label'       => '分类映射',
        'type'        => 'textarea',
        'required'    => false,
        'description' => 'JSON 数组，例如 [{"real_id":10,"public_id":110}]。一键处理会保留已有关系并为其余启用分类补齐同 ID 映射；分类导航直接复用此映射。',
        'rules'       => 'nullable|json',
    ],
];
