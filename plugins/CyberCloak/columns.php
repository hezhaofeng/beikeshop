<?php

use Beike\Models\Country;

// 优先使用店铺已维护的完整国家表；安装早期保留常用国家，避免下拉为空。
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
        ->filter(fn (Country $country): bool => $country->code !== '')
        ->map(fn (Country $country): array => [
            'value' => strtoupper($country->code),
            'label' => strtoupper($country->code) . ' - ' . $country->name,
        ])
        ->values()
        ->all();

    if ($storedCountries !== []) {
        $countryOptions = $storedCountries;
    }
} catch (\Throwable) {
    // 数据库尚未初始化时使用上方默认选项。
}

$languageOptions = [
    ['value' => 'ZH-CN', 'label' => '简体中文 (zh-CN)'],
    ['value' => 'ZH-TW', 'label' => '繁体中文 (zh-TW)'],
    ['value' => 'ZH-HK', 'label' => '繁体中文 (zh-HK)'],
    ['value' => 'EN', 'label' => 'English (en)'],
    ['value' => 'EN-US', 'label' => 'English, United States (en-US)'],
    ['value' => 'EN-GB', 'label' => 'English, United Kingdom (en-GB)'],
    ['value' => 'JA', 'label' => '日本語 (ja)'],
    ['value' => 'KO', 'label' => '한국어 (ko)'],
    ['value' => 'DE', 'label' => 'Deutsch (de)'],
    ['value' => 'FR', 'label' => 'Français (fr)'],
    ['value' => 'ES', 'label' => 'Español (es)'],
    ['value' => 'PT-BR', 'label' => 'Português do Brasil (pt-BR)'],
    ['value' => 'IT', 'label' => 'Italiano (it)'],
    ['value' => 'RU', 'label' => 'Русский (ru)'],
    ['value' => 'AR', 'label' => 'العربية (ar)'],
    ['value' => 'HI', 'label' => 'हिन्दी (hi)'],
    ['value' => 'ID', 'label' => 'Bahasa Indonesia (id)'],
    ['value' => 'TH', 'label' => 'ไทย (th)'],
    ['value' => 'VI', 'label' => 'Tiếng Việt (vi)'],
];

$userAgentOptions = [
    ['value' => 'HEADLESSCHROME', 'label' => 'HeadlessChrome'],
    ['value' => 'SELENIUM', 'label' => 'Selenium'],
    ['value' => 'PUPPETEER', 'label' => 'Puppeteer'],
    ['value' => 'PLAYWRIGHT', 'label' => 'Playwright'],
    ['value' => 'PHANTOMJS', 'label' => 'PhantomJS'],
    ['value' => 'CURL', 'label' => 'curl'],
    ['value' => 'WGET', 'label' => 'Wget'],
    ['value' => 'POSTMANRUNTIME', 'label' => 'PostmanRuntime'],
    ['value' => 'PYTHON-REQUESTS', 'label' => 'python-requests'],
    ['value' => 'GO-HTTP-CLIENT', 'label' => 'Go-http-client'],
    ['value' => 'JAVA/', 'label' => 'Java HTTP Client'],
    ['value' => 'OKHTTP', 'label' => 'okhttp'],
    ['value' => 'SCRAPY', 'label' => 'Scrapy'],
    ['value' => 'AIOHTTP', 'label' => 'aiohttp'],
    ['value' => 'HTTPX', 'label' => 'httpx'],
    // 平台专用爬虫标记，刻意不使用 Instagram 等宽泛关键词，以免误伤 App 内置浏览器。
    ['value' => 'GOOGLEBOT', 'label' => 'Googlebot'],
    ['value' => 'ADSBOT-GOOGLE-MOBILE', 'label' => 'AdsBot-Google-Mobile'],
    ['value' => 'ADSBOT-GOOGLE', 'label' => 'AdsBot-Google'],
    ['value' => 'MEDIAPARTNERS-GOOGLE', 'label' => 'Mediapartners-Google'],
    ['value' => 'STOREBOT-GOOGLE', 'label' => 'Storebot-Google'],
    ['value' => 'GOOGLE-INSPECTIONTOOL', 'label' => 'Google-InspectionTool'],
    ['value' => 'GOOGLEOTHER', 'label' => 'GoogleOther'],
    ['value' => 'GOOGLE-EXTENDED', 'label' => 'Google-Extended'],
    ['value' => 'FACEBOOKEXTERNALHIT', 'label' => 'facebookexternalhit'],
    ['value' => 'FACEBOOKCATALOG', 'label' => 'facebookcatalog'],
    ['value' => 'FACEBOT', 'label' => 'Facebot'],
    ['value' => 'META-EXTERNALAGENT', 'label' => 'meta-externalagent'],
    ['value' => 'META-EXTERNALFETCHER', 'label' => 'meta-externalfetcher'],
    ['value' => 'INSTAGRAMBOT', 'label' => 'InstagramBot'],
];

return [
    [
        'name'        => 'ip_whitelist',
        'label'       => 'IP 白名单',
        'type'        => 'textarea',
        'required'    => false,
        'description' => '每行一个 IPv4、IPv6 或 CIDR。为空表示不启用白名单限制。',
        'rules'       => 'nullable|string',
    ],
    [
        'name'        => 'ip_blacklist',
        'label'       => 'IP 黑名单',
        'type'        => 'textarea',
        'required'    => false,
        'description' => '每行一个 IPv4、IPv6 或 CIDR。黑名单优先于 key 和 Cookie。',
        'rules'       => 'nullable|string',
    ],
    [
        'name'        => 'ip_reputation',
        'label'       => 'IP 信誉高风险网段',
        'type'        => 'textarea',
        'required'    => false,
        'description' => '每行一个 IPv4、IPv6 或 CIDR。命中后作为第二层软风险信号，不直接覆盖黑名单逻辑。',
        'rules'       => 'nullable|string',
    ],
    [
        'name'        => 'geoip_enabled',
        'label'       => '启用本地 MaxMind 查询',
        'type'        => 'bool',
        'required'    => false,
        'description' => '使用本地 MMDB 查询国家和 ASN；前台请求不会调用远程 API。',
        'rules'       => 'nullable|boolean',
    ],
    [
        'name'        => 'geoip_country_database',
        'label'       => 'MaxMind Country 数据库路径',
        'type'        => 'string',
        'required'    => false,
        'description' => '服务器本地 GeoLite2-Country.mmdb 的绝对路径。',
        'rules'       => 'nullable|string|max:2048',
    ],
    [
        'name'        => 'geoip_asn_database',
        'label'       => 'MaxMind ASN 数据库路径',
        'type'        => 'string',
        'required'    => false,
        'description' => '服务器本地 GeoLite2-ASN.mmdb 的绝对路径。',
        'rules'       => 'nullable|string|max:2048',
    ],
    [
        'name'        => 'geoip_anonymous_database',
        'label'       => 'MaxMind 匿名 IP 数据库路径',
        'type'        => 'string',
        'required'    => false,
        'description' => '可选的匿名 IP 数据库路径；GeoLite2 默认不包含住宅代理识别数据。',
        'rules'       => 'nullable|string|max:2048',
    ],
    [
        'name'        => 'cloud_ip_ranges_database',
        'label'       => '云 IP 汇总数据库路径',
        'type'        => 'string',
        'required'    => false,
        'description' => '本地 JSON 文件。推荐使用 providers: {"AWS":["1.2.3.0/24"]}；命中只标记数据中心和厂商，不用于判定家庭 IP。',
        'rules'       => 'nullable|string|max:2048',
    ],
    [
        'name'        => 'traffic_funnel_enabled',
        'label'       => '启用四层流量漏斗',
        'type'        => 'bool',
        'required'    => false,
        'description' => 'query key 和签名 Cookie 校验失败后，按急速拦截、核心风控、规则匹配、深度识别逐层计算风险。',
        'rules'       => 'nullable|boolean',
    ],
    [
        'name'        => 'traffic_allowed_countries',
        'label'       => '漏斗允许国家/地区',
        'type'        => 'select-multiple',
        'options'     => $countryOptions,
        'required'    => false,
        'description' => '仅对无有效 key/Cookie 的流量生效；配置后，不匹配或未识别的国家/地区会直接进入展示漏斗。',
        'rules'       => 'nullable|array',
    ],
    [
        'name'        => 'traffic_allowed_languages',
        'label'       => '浏览器语言白名单',
        'type'        => 'select-multiple',
        'options'     => $languageOptions,
        'required'    => false,
        'description' => '仅对无有效 key/Cookie 的流量生效；匹配 Accept-Language，基础语言与地区语言可兼容匹配。',
        'rules'       => 'nullable|array',
    ],
    [
        'name'        => 'traffic_user_agent_blacklist',
        'label'       => 'UA 黑名单关键词',
        'type'        => 'select-multiple',
        'options'     => $userAgentOptions,
        'required'    => false,
        'description' => '仅对无有效 key/Cookie 的流量生效；大小写不敏感匹配 User-Agent 并增加风险分。',
        'rules'       => 'nullable|array',
    ],
    [
        'name'        => 'traffic_blocked_asns',
        'label'       => '漏斗 ASN 黑名单',
        'type'        => 'textarea',
        'required'    => false,
        'description' => '每行一个 ASN。ASN 命中会增加高风险分，最终动作由阈值决定。',
        'rules'       => 'nullable|string',
    ],
    [
        'name'        => 'traffic_datacenter_asns',
        'label'       => '数据中心 ASN 列表',
        'type'        => 'textarea',
        'required'    => false,
        'description' => '每行一个 ASN。数据中心只作为风险信号，不单独等同于代理或恶意来源。',
        'rules'       => 'nullable|string',
    ],
    [
        'name'        => 'traffic_rate_limit_enabled',
        'label'       => '启用漏斗频率限制',
        'type'        => 'bool',
        'required'    => false,
        'description' => '使用当前 Laravel 缓存驱动按 IP 与路由计数；启用前请确认 Redis 或其他共享缓存可用。',
        'rules'       => 'nullable|boolean',
    ],
    [
        'name'        => 'traffic_rate_limit_max',
        'label'       => '单窗口最大请求数',
        'type'        => 'string',
        'required'    => false,
        'description' => '频控开启后按 IP 与路由计算，建议普通页面和敏感接口分别配置策略。',
        'rules'       => 'nullable|integer|min:1|max:100000',
    ],
    [
        'name'        => 'traffic_rate_limit_decay',
        'label'       => '频控窗口秒数',
        'type'        => 'string',
        'required'    => false,
        'rules'       => 'nullable|integer|min:1|max:86400',
    ],
    [
        'name'        => 'traffic_block_threshold',
        'label'       => '漏斗封禁分数',
        'type'        => 'string',
        'required'    => false,
        'rules'       => 'nullable|integer|min:1|max:100',
    ],
    [
        'name'        => 'traffic_challenge_threshold',
        'label'       => '漏斗挑战分数',
        'type'        => 'string',
        'required'    => false,
        'rules'       => 'nullable|integer|min:1|max:100',
    ],
    [
        'name'        => 'traffic_fingerprint_cookie',
        'label'       => '浏览器指纹标记 Cookie',
        'type'        => 'string',
        'required'    => false,
        'description' => '仅记录指纹是否存在，不把客户端指纹直接作为单一封禁条件。',
        'rules'       => 'nullable|string|max:128',
    ],
    [
        'name'        => 'traffic_behavior_enabled',
        'label'       => '启用行为识别',
        'type'        => 'bool',
        'required'    => false,
        'description' => '对无有效 key/Cookie 流量统计短窗口请求数、路由遍历和重复无效上下文，用于发现未知自动化模式。',
        'rules'       => 'nullable|boolean',
    ],
    [
        'name'        => 'traffic_behavior_window',
        'label'       => '行为窗口秒数',
        'type'        => 'string',
        'required'    => false,
        'rules'       => 'nullable|integer|min:1|max:3600',
    ],
    [
        'name'        => 'traffic_behavior_max_requests',
        'label'       => '行为窗口最大请求数',
        'type'        => 'string',
        'required'    => false,
        'rules'       => 'nullable|integer|min:1|max:100000',
    ],
    [
        'name'        => 'traffic_behavior_max_routes',
        'label'       => '行为窗口最大路由数',
        'type'        => 'string',
        'required'    => false,
        'rules'       => 'nullable|integer|min:1|max:10000',
    ],
    [
        'name'        => 'traffic_behavior_invalid_cookie_max',
        'label'       => '重复无效 Cookie 阈值',
        'type'        => 'string',
        'required'    => false,
        'description' => '同一 IP 在行为窗口内持续携带未通过校验的上下文 Cookie 时增加风险分。',
        'rules'       => 'nullable|integer|min:1|max:10000',
    ],
    [
        'name'        => 'traffic_behavior_score',
        'label'       => '单项行为风险分',
        'type'        => 'string',
        'required'    => false,
        'rules'       => 'nullable|integer|min:1|max:100',
    ],
    [
        'name'        => 'traffic_risk_audit_enabled',
        'label'       => '启用风险 IP 审核记录',
        'type'        => 'bool',
        'required'    => false,
        'description' => '达到审核分数的请求写入加密 IP 档案与风险事件，供后台人工标记可信、可疑或封禁。',
        'rules'       => 'nullable|boolean',
    ],
    [
        'name'        => 'traffic_risk_audit_threshold',
        'label'       => '风险审核分数',
        'type'        => 'string',
        'required'    => false,
        'rules'       => 'nullable|integer|min:1|max:100',
    ],
];
