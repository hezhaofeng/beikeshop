<?php

namespace Plugin\CyberCloak\Services;

use Beike\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class TrafficFunnelService
{
    /** @var array<string,mixed> */
    private array $settingCache = [];

    private bool $settingsLoaded = false;

    public function __construct(
        private readonly ?MaxMindIpIntelligence $intelligence = null,
        private readonly ?TrafficBehaviorService $behavior = null,
        private readonly ?TrafficRiskAuditService $riskAudit = null,
    )
    {
    }

    /**
     * 按急速拦截、核心风控、规则匹配、深度识别逐层计算风险。
     *
     * 只有 query key 和签名 Cookie 校验失败的请求才进入这里；有效凭据由 CatalogResolver 直接进入真实站。
     * 每个信号都返回原因码，便于 Shadow Mode、误杀分析和后台审计。
     *
     * @param array{ip:string,blacklisted:bool} $ipResult
     * @return array{action:string,stage:string,score:int,reasons:array<int,string>,signals:array<string,mixed>,ip:string}
     */
    public function evaluate(Request $request, array $ipResult): array
    {
        $ip     = (string) ($ipResult['ip'] ?? '0.0.0.0');
        $result = [
            'action'  => 'allow',
            'stage'   => 'fast',
            'score'   => 0,
            'reasons' => [],
            'signals' => [],
            'ip'      => $ip,
        ];

        if (! $this->boolSetting('traffic_funnel_enabled', true)) {
            return $result;
        }

        $blockThreshold     = max(1, (int) $this->setting('traffic_block_threshold', 80));
        $challengeThreshold = max(1, (int) $this->setting('traffic_challenge_threshold', 50));

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->addSignal($result, 100, 'invalid_client_ip', true);

            return $this->decision($request, $result, 80, 'fast');
        }

        // 第一层：静态黑名单已经在 IpAccessService 完成地址和网段匹配。
        if (($ipResult['blacklisted'] ?? false) === true) {
            $this->addSignal($result, 100, 'static_ip_blacklist', true);

            return $this->decision($request, $result, 80, 'fast');
        }

        // 人工封禁是高优先级风险信号；可信状态只跳过行为计分，不跳过基础规则。
        $manualRiskStatus = ($this->riskAudit ?: new TrafficRiskAuditService)->profileStatus($ip);
        if ($manualRiskStatus === 'blocked') {
            $this->addSignal($result, 100, 'manual_risk_block', $manualRiskStatus);

            return $this->decision($request, $result, 80, 'fast');
        }
        if ($manualRiskStatus === 'suspicious') {
            // 人工可疑结论保留为软信号，仍由总阈值决定挑战或封禁动作。
            $this->addSignal($result, 20, 'manual_risk_suspicious', $manualRiskStatus);
        }

        $policy            = $this->policySignals($request, $ipResult);
        $result['signals'] = array_merge($result['signals'], $policy['signals']);
        foreach ($policy['reasons'] as $reason) {
            $this->addSignal($result, match ($reason) {
                // 配置允许国家后，未知或不匹配来源固定进入展示漏斗。
                'country_not_allowed'  => $challengeThreshold,
                'language_not_allowed' => 30,
                'ua_blacklist'         => 60,
                'malformed_user_agent' => 10,
                default                => 20,
            }, $reason, $policy['signals'][$reason] ?? true);
        }

        $userAgent    = $policy['user_agent'];
        $intelligence = $policy['intelligence'];

        // 第二层：ASN 是风险线索，不把 ASN 本身等同于代理或恶意来源。
        if (($ipResult['reputation'] ?? false) === true) {
            $this->addSignal($result, 30, 'ip_reputation', true);
        }

        $blockedAsns = $this->integerListSetting('traffic_blocked_asns');
        $asn         = (int) ($intelligence['asn'] ?? 0);
        if ($asn > 0 && in_array($asn, $blockedAsns, true)) {
            $this->addSignal($result, 45, 'blocked_asn', $asn);
        }
        if (($intelligence['anonymous'] ?? null) === true) {
            $this->addSignal($result, 30, 'anonymous_ip', true);
        }
        if (($intelligence['network_type'] ?? '') === 'DATACENTER') {
            $this->addSignal($result, 20, 'cloud_ip_range', [
                'provider' => $intelligence['cloud_provider'] ?? 'CLOUD',
                'cidr'     => $intelligence['cloud_range'] ?? null,
            ]);
        }

        $datacenterAsns = $this->integerListSetting('traffic_datacenter_asns');
        if ($asn > 0 && in_array($asn, $datacenterAsns, true)) {
            $this->addSignal($result, 20, 'datacenter_asn', $asn);
        }

        // 核心风控已经达到封禁阈值时直接结束，避免继续消耗频控和深度检测资源。
        if ($result['score'] >= $blockThreshold) {
            return $this->decision($request, $result, $blockThreshold, 'core', null, $challengeThreshold);
        }

        // 行为画像只覆盖没有有效 key 或签名 Cookie 的请求，识别新 Bot 的高频和路由扫描模式。
        if ($manualRiskStatus !== 'trusted') {
            $behavior = ($this->behavior ?: new TrafficBehaviorService)->observe(
                $request,
                $ip,
                $this->behaviorPolicy(),
                [
                    'asn'            => $asn > 0 ? $asn : null,
                    'risk_asn'       => $asn > 0 && in_array($asn, $blockedAsns, true),
                    'anonymous'      => ($intelligence['anonymous'] ?? null) === true,
                    'datacenter'     => ($intelligence['network_type'] ?? '') === 'DATACENTER'
                        || ($asn > 0 && in_array($asn, $datacenterAsns, true)),
                    'cloud_provider' => $intelligence['cloud_provider'] ?? null,
                    'network_type'   => (string) ($intelligence['network_type'] ?? 'UNKNOWN'),
                ],
            );
            $result['signals'] = array_merge($result['signals'], $behavior['signals']);
            foreach ($behavior['reasons'] as $reason) {
                $this->addSignal($result, (int) ($behavior['scores'][$reason] ?? 0), $reason, $behavior['signals'][$reason] ?? true);
            }
        } else {
            $result['signals']['manual_risk_status'] = 'trusted';
        }

        // 第三层：频控默认关闭，启用后使用 Laravel RateLimiter，避免引入额外表结构。
        if ($this->boolSetting('traffic_rate_limit_enabled', false)) {
            $route = trim((string) $request->route()?->getName());
            $key   = 'cyber-cloak:traffic:' . sha1($ip . '|' . $route);
            $max   = max(1, (int) $this->setting('traffic_rate_limit_max', 120));
            $decay = max(1, (int) $this->setting('traffic_rate_limit_decay', 60));

            try {
                if (RateLimiter::tooManyAttempts($key, $max)) {
                    $this->addSignal($result, 35, 'rate_limit_exceeded', ['max' => $max, 'decay' => $decay]);
                } else {
                    RateLimiter::hit($key, $decay);
                }
            } catch (\Throwable) {
                // 缓存驱动暂不可用时记录降级信号，不让频控故障扩大为全站故障。
                $result['signals']['rate_limiter'] = 'unavailable';
            }
        }

        // 规则层已经达到阈值时不再执行浏览器深度识别。
        if ($result['score'] >= $blockThreshold) {
            return $this->decision($request, $result, $blockThreshold, 'rules', null, $challengeThreshold);
        }

        // 第四层：服务端只采集弱指纹和自动化线索，浏览器指纹脚本按路由另行接入。
        $fingerprintCookie = (string) $this->setting('traffic_fingerprint_cookie', '');
        if ($fingerprintCookie !== '' && $request->hasCookie($fingerprintCookie)) {
            $result['signals']['fingerprint'] = 'present';
        }
        if ($this->looksAutomated($request, $userAgent)) {
            $this->addSignal($result, 45, 'automation_signal', true);
        }

        return $this->decision(
            $request,
            $result,
            $blockThreshold,
            'deep',
            null,
            $challengeThreshold
        );
    }

    /**
     * 汇总动作阈值；Shadow Mode 可只记录结果而由调用方继续展示模式。
     */
    private function decision(Request $request, array $result, int $blockThreshold, string $stage, string $reason = null, int $challengeThreshold = 50): array
    {
        if ($reason !== null) {
            $result['reasons'][] = $reason;
        }
        $result['stage'] = $stage;
        if ($result['score'] >= $blockThreshold) {
            $result['action'] = 'block';
        } elseif (isset($result['signals']['rate_limit_exceeded'])) {
            $result['action'] = 'rate_limit';
        } elseif ($result['score'] >= $challengeThreshold) {
            $result['action'] = 'challenge';
        } elseif ($result['score'] > 0) {
            $result['action'] = 'observe';
        }

        // 所有决策路径都经过此处，避免快速黑名单和深度识别出现审计遗漏。
        ($this->riskAudit ?: new TrafficRiskAuditService)->record(
            $request,
            $result,
            $this->boolSetting('traffic_risk_audit_enabled', true),
            max(1, (int) $this->setting('traffic_risk_audit_threshold', 20)),
        );

        return $result;
    }

    /**
     * 追加一个可解释风险信号，并限制分数在 0 到 100 之间。
     */
    private function addSignal(array &$result, int $score, string $reason, mixed $value): void
    {
        $result['score']            = min(100, $result['score'] + max(0, $score));
        $result['reasons'][]        = $reason;
        $result['signals'][$reason] = $value;
    }

    /**
     * 识别明显自动化客户端；仅作为深度层信号，不单独决定真实 key 请求。
     */
    private function looksAutomated(Request $request, string $userAgent): bool
    {
        if ($request->boolean('X-Cyber-Automation') || $request->header('X-Cyber-Automation') === '1') {
            return true;
        }

        return (bool) preg_match('/headless|phantomjs|selenium|puppeteer|playwright|webdriver/i', $userAgent);
    }

    /**
     * 汇总真实页面白名单和第一层基础 UA 信号，供真实模式和完整漏斗共同复用。
     *
     * @return array{reasons:array<int,string>,signals:array<string,mixed>,user_agent:string,intelligence:array<string,mixed>}
     */
    private function policySignals(Request $request, array $ipResult): array
    {
        $ip           = (string) ($ipResult['ip'] ?? '0.0.0.0');
        $userAgent    = trim((string) $request->userAgent());
        $intelligence = [
            'country'      => null,
            'asn'          => null,
            'organization' => null,
            'anonymous'    => null,
            'cloud_provider' => null,
            'network_type' => 'UNKNOWN',
            'cloud_range'  => null,
            'available'    => false,
            'source'       => 'none',
        ];
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $intelligence = ($this->intelligence ?: new MaxMindIpIntelligence)->lookup($ip);
        }

        $country           = strtoupper((string) ($intelligence['country'] ?? ''));
        $languages         = $this->requestLanguages($request);
        $allowedCountries  = $this->listSetting('traffic_allowed_countries');
        $allowedLanguages  = $this->listSetting('traffic_allowed_languages');
        $uaBlacklist       = $this->listSetting('traffic_user_agent_blacklist');
        $reasons           = [];
        $signals           = [
            'ip'            => $ip,
            'country'       => $country !== '' ? $country : null,
            'asn'           => $intelligence['asn'],
            'organization'  => $intelligence['organization'],
            'anonymous'     => $intelligence['anonymous'],
            'cloud_provider' => $intelligence['cloud_provider'] ?? null,
            'network_type' => $intelligence['network_type'] ?? 'UNKNOWN',
            'cloud_range'  => $intelligence['cloud_range'] ?? null,
            'languages'     => $languages,
            'user_agent'    => $userAgent !== '' ? $userAgent : null,
        ];

        // 国家、语言和 UA 仅作为无凭据流量的漏斗信号；允许国家配置存在时，未知国家也进入展示漏斗。
        if ($allowedCountries !== [] && ($country === '' || ! in_array($country, $allowedCountries, true))) {
            $reasons[]                      = 'country_not_allowed';
            $signals['country_not_allowed'] = $country !== '' ? $country : 'unknown';
        }
        if ($allowedLanguages !== [] && ! $this->matchesLanguageWhitelist($languages, $allowedLanguages)) {
            $reasons[]                       = 'language_not_allowed';
            $signals['language_not_allowed'] = $languages !== [] ? $languages : 'unknown';
        }
        $matchedUa = $this->matchesUserAgentBlacklist($userAgent, $uaBlacklist);
        if ($matchedUa !== null) {
            $reasons[]               = 'ua_blacklist';
            $signals['ua_blacklist'] = $matchedUa;
        }
        if ($userAgent === '' || strlen($userAgent) > 512 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $userAgent)) {
            $reasons[]                       = 'malformed_user_agent';
            $signals['malformed_user_agent'] = ['present' => $userAgent !== ''];
        }

        return [
            'reasons'      => array_values(array_unique($reasons)),
            'signals'      => $signals,
            'user_agent'   => $userAgent,
            'intelligence' => $intelligence,
        ];
    }

    /**
     * 解析 Accept-Language，并忽略 q 权重后的语言标签。
     *
     * @return array<int,string>
     */
    private function requestLanguages(Request $request): array
    {
        $header    = (string) $request->header('Accept-Language', '');
        $languages = [];
        foreach (explode(',', $header) as $item) {
            $language = strtoupper(trim((string) explode(';', $item, 2)[0]));
            if ($language !== '' && $language !== '*' && preg_match('/^[A-Z]{2,3}(?:-[A-Z0-9]{2,8})*$/', $language)) {
                $languages[] = $language;
            }
        }

        return array_values(array_unique($languages));
    }

    /**
     * 语言白名单支持 zh 与 zh-CN 的基础语言兼容匹配。
     */
    private function matchesLanguageWhitelist(array $languages, array $allowed): bool
    {
        foreach ($languages as $language) {
            foreach ($allowed as $item) {
                if ($language === $item || str_starts_with($language, $item . '-') || str_starts_with($item, $language . '-')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * UA 黑名单采用大小写不敏感关键词匹配，避免后台输入正则造成配置错误。
     */
    private function matchesUserAgentBlacklist(string $userAgent, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && stripos($userAgent, $pattern) !== false) {
                return $pattern;
            }
        }

        return null;
    }

    /**
     * 读取列表型设置，兼容 JSON、换行、逗号和分号格式。
     *
     * @return array<int,string>
     */
    private function listSetting(string $name): array
    {
        $value = $this->setting($name, '');
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value   = is_array($decoded) ? $decoded : preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($item) => strtoupper(trim((string) $item)), $value)));
    }

    /**
     * 读取 ASN 列表并去除无效数字。
     *
     * @return array<int,int>
     */
    private function integerListSetting(string $name): array
    {
        return array_values(array_filter(array_map(
            fn ($item) => ctype_digit((string) $item) ? (int) $item : null,
            $this->listSetting($name)
        ), fn ($item) => $item !== null));
    }

    /**
     * 将后台行为识别设置转换为缓存服务所需的紧凑策略。
     *
     * @return array{enabled:bool,window:int,max_requests:int,max_routes:int,invalid_cookie_max:int,score:int,cookie_name:string}
     */
    private function behaviorPolicy(): array
    {
        return [
            'enabled'            => $this->boolSetting('traffic_behavior_enabled', true),
            'window'             => max(1, (int) $this->setting('traffic_behavior_window', 60)),
            'max_requests'       => max(1, (int) $this->setting('traffic_behavior_max_requests', 60)),
            'max_routes'         => max(1, (int) $this->setting('traffic_behavior_max_routes', 12)),
            'invalid_cookie_max' => max(1, (int) $this->setting('traffic_behavior_invalid_cookie_max', 3)),
            'score'              => max(1, min(100, (int) $this->setting('traffic_behavior_score', 20))),
            'cookie_name'        => (string) config('cyber_cloak.cookie_name', 'beike_context'),
        ];
    }

    /**
     * 读取动态设置，数据库未安装时回退到插件配置。
     */
    private function setting(string $name, mixed $default): mixed
    {
        if (array_key_exists($name, $this->settingCache)) {
            return $this->settingCache[$name];
        }

        if (app()->environment('testing')) {
            $value = function_exists('plugin_setting') ? plugin_setting("cyber_cloak.{$name}", null) : null;

            return $this->settingCache[$name] = ($value !== null && $value !== '')
                ? $value
                : config("cyber_cloak.{$name}", $default);
        }

        $this->loadSettings();
        if (array_key_exists($name, $this->settingCache)) {
            return $this->settingCache[$name];
        }

        $value = function_exists('plugin_setting') ? plugin_setting("cyber_cloak.{$name}", null) : null;

        return $this->settingCache[$name] = ($value !== null && $value !== '')
            ? $value
            : config("cyber_cloak.{$name}", $default);
    }

    /**
     * 一次读取漏斗相关设置，避免每个信号都单独查询 settings 表。
     */
    private function loadSettings(): void
    {
        if ($this->settingsLoaded) {
            return;
        }
        $this->settingsLoaded = true;

        try {
            Setting::query()
                ->where('type', 'plugin')
                ->where('space', 'cyber_cloak')
                ->whereIn('name', [
                    'traffic_funnel_enabled', 'traffic_allowed_countries', 'traffic_blocked_asns',
                    'traffic_datacenter_asns', 'traffic_rate_limit_enabled', 'traffic_rate_limit_max',
                    'traffic_rate_limit_decay', 'traffic_block_threshold', 'traffic_challenge_threshold',
                    'traffic_fingerprint_cookie', 'traffic_allowed_languages', 'traffic_user_agent_blacklist',
                    'traffic_behavior_enabled', 'traffic_behavior_window', 'traffic_behavior_max_requests',
                    'traffic_behavior_max_routes', 'traffic_behavior_invalid_cookie_max', 'traffic_behavior_score',
                    'traffic_risk_audit_enabled', 'traffic_risk_audit_threshold',
                ])
                ->get()
                ->each(function ($setting): void {
                    $this->settingCache[$setting->name] = $setting->json
                        ? json_decode((string) $setting->value, true)
                        : $setting->value;
                });
        } catch (\Throwable) {
            // 单元测试和安装早期没有 settings 表时继续使用配置。
        }
    }

    /**
     * 将后台或环境变量中的布尔值统一解释。
     */
    private function boolSetting(string $name, bool $default): bool
    {
        return filter_var($this->setting($name, $default), FILTER_VALIDATE_BOOL);
    }
}
