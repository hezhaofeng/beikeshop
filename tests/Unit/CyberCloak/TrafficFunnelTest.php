<?php

namespace Tests\Unit\CyberCloak;

use Illuminate\Http\Request;
use Plugin\CyberCloak\Services\AccessKeyService;
use Plugin\CyberCloak\Services\CatalogResolver;
use Plugin\CyberCloak\Services\ContextTicketService;
use Plugin\CyberCloak\Services\IpAccessService;
use Plugin\CyberCloak\Services\MaxMindIpIntelligence;
use Plugin\CyberCloak\Services\TrafficFunnelService;
use Plugin\CyberCloak\Services\TrafficRiskAuditService;
use Tests\TestCase;

class TrafficFunnelTest extends TestCase
{
    /**
     * 初始化漏斗测试的默认阈值和配置。
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'traffic_funnel_enabled'       => true,
            'traffic_block_threshold'      => 80,
            'traffic_challenge_threshold'  => 40,
            'traffic_rate_limit_enabled'   => false,
            'traffic_rate_limit_max'       => 120,
            'traffic_rate_limit_decay'     => 60,
            'traffic_blocked_asns'         => '',
            'traffic_datacenter_asns'      => '',
            'traffic_datacenter_asns'      => '',
            'traffic_allowed_countries'    => '',
            'traffic_allowed_languages'    => '',
            'traffic_user_agent_blacklist' => '',
            'traffic_fingerprint_cookie'   => '',
            'traffic_behavior_enabled'     => false,
            'traffic_risk_audit_enabled'   => false,
        ] as $name => $value) {
            $this->setTrafficSetting($name, $value);
        }
    }

    /**
     * 自动化信号进入第四层并触发挑战动作，但单一信号不直接封禁。
     */
    public function test_automation_signal_enters_challenge(): void
    {
        $request = Request::create('/products', 'GET', [], [], [], [
            'REMOTE_ADDR'     => '203.0.113.10',
            'HTTP_USER_AGENT' => 'HeadlessChrome/126.0',
        ]);

        $result = $this->funnel()->evaluate($request, $this->ipResult());

        $this->assertSame('challenge', $result['action']);
        $this->assertSame('deep', $result['stage']);
        $this->assertContains('automation_signal', $result['reasons']);
    }

    /**
     * ASN 黑名单和自动化信号叠加达到封禁阈值。
     */
    public function test_blocked_asn_and_automation_are_blocked(): void
    {
        $this->setTrafficSetting('traffic_blocked_asns', '64500');
        $intelligence = $this->intelligence(['asn' => 64500]);
        $request      = Request::create('/products', 'GET', [], [], [], [
            'REMOTE_ADDR'     => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Selenium',
        ]);

        $result = (new TrafficFunnelService($intelligence))->evaluate($request, $this->ipResult());

        $this->assertSame('block', $result['action']);
        $this->assertGreaterThanOrEqual(80, $result['score']);
        $this->assertContains('blocked_asn', $result['reasons']);
    }

    /**
     * IP 信誉命中作为第二层软信号，叠加自动化特征后进入挑战阈值。
     */
    public function test_ip_reputation_is_a_soft_signal(): void
    {
        $request = Request::create('/products', 'GET', [], [], [], [
            'REMOTE_ADDR'     => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Selenium',
        ]);
        $ipResult = array_merge($this->ipResult(), ['reputation' => true]);

        $result = $this->funnel()->evaluate($request, $ipResult);

        $this->assertSame('challenge', $result['action']);
        $this->assertContains('ip_reputation', $result['reasons']);
    }

    /**
     * 漏斗关闭时保留展示模式默认放行结果，不影响现有 key/IP 解析。
     */
    public function test_disabled_funnel_returns_allow(): void
    {
        $this->setTrafficSetting('traffic_funnel_enabled', false);
        $request = Request::create('/products', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']);

        $result = $this->funnel()->evaluate($request, $this->ipResult());

        $this->assertSame('allow', $result['action']);
        $this->assertSame(0, $result['score']);
    }

    /**
     * 无有效凭据的流量会累积国家、语言和 UA 信号，并按阈值执行漏斗动作。
     */
    public function test_country_language_and_ua_signals_are_applied_to_the_funnel(): void
    {
        $this->setTrafficSetting('traffic_allowed_countries', 'CN');
        $this->setTrafficSetting('traffic_allowed_languages', "zh-CN\nen");
        $this->setTrafficSetting('traffic_user_agent_blacklist', "HeadlessChrome\nSelenium");
        $intelligence = $this->intelligence(['country' => 'CN']);
        $service      = new TrafficFunnelService($intelligence);

        $request = Request::create('/products', 'GET', [], [], [], [
            'REMOTE_ADDR'          => '203.0.113.10',
            'HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9',
            'HTTP_USER_AGENT'      => 'HeadlessChrome/126.0',
        ]);
        $result = $service->evaluate($request, $this->ipResult());

        $this->assertSame('block', $result['action']);
        $this->assertContains('language_not_allowed', $result['reasons']);
        $this->assertContains('ua_blacklist', $result['reasons']);
    }

    /**
     * 已配置允许国家时，未匹配和未知地区都直接进入展示漏斗。
     */
    public function test_non_allowed_country_is_forced_into_the_public_funnel(): void
    {
        $this->setTrafficSetting('traffic_allowed_countries', 'CN');
        $request = Request::create('/products', 'GET', [], [], [], [
            'REMOTE_ADDR'     => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
        ]);

        $result = (new TrafficFunnelService($this->intelligence(['country' => 'US'])))->evaluate($request, $this->ipResult());

        $this->assertSame('challenge', $result['action']);
        $this->assertContains('country_not_allowed', $result['reasons']);
    }

    /**
     * 默认 UA 黑名单应识别常见平台的专用爬虫，同时不误伤普通浏览器。
     */
    public function test_default_platform_crawler_user_agents_are_blacklisted(): void
    {
        $platformCrawlers = [
            'Googlebot/2.1 (+http://www.google.com/bot.html)' => 'Googlebot',
            'AdsBot-Google-Mobile' => 'AdsBot-Google-Mobile',
            'AdsBot-Google (+http://www.google.com/adsbot.html)' => 'AdsBot-Google',
            'Mediapartners-Google' => 'Mediapartners-Google',
            'Storebot-Google' => 'Storebot-Google',
            'Google-InspectionTool' => 'Google-InspectionTool',
            'GoogleOther' => 'GoogleOther',
            'Google-Extended' => 'Google-Extended',
            'facebookexternalhit/1.1' => 'facebookexternalhit',
            'facebookcatalog/1.0' => 'facebookcatalog',
            'Facebot' => 'Facebot',
            'meta-externalagent/1.1' => 'meta-externalagent',
            'meta-externalfetcher/1.1' => 'meta-externalfetcher',
            'InstagramBot/1.0' => 'InstagramBot',
        ];
        $config = file_get_contents(base_path('plugins/CyberCloak/Config/cyber_cloak.php'));

        foreach (array_unique(array_values($platformCrawlers)) as $pattern) {
            $this->assertStringContainsString("'{$pattern}'", $config);
        }
        foreach (['PayPal', 'Stripe', 'Wise'] as $paymentPlatform) {
            $this->assertStringNotContainsString("'{$paymentPlatform}'", $config);
        }

        // 环境变量可覆盖默认配置，测试显式注入默认规则以隔离部署环境。
        $this->setTrafficSetting('traffic_user_agent_blacklist', implode("\n", array_values($platformCrawlers)));

        foreach ($platformCrawlers as $userAgent => $expectedPattern) {
            $request = Request::create('/products', 'GET', [], [], [], [
                'REMOTE_ADDR' => '203.0.113.10',
                'HTTP_USER_AGENT' => $userAgent,
            ]);

            $result = $this->funnel()->evaluate($request, $this->ipResult());

            $this->assertSame('challenge', $result['action'], $userAgent);
            $this->assertContains('ua_blacklist', $result['reasons'], $userAgent);
            $this->assertSame(strtoupper($expectedPattern), $result['signals']['ua_blacklist'], $userAgent);
        }

        $browserRequest = Request::create('/products', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/126.0 Mobile Safari/537.36 Instagram 336.0.0.0.0',
        ]);
        $browserResult = $this->funnel()->evaluate($browserRequest, $this->ipResult());

        $this->assertSame('allow', $browserResult['action']);
        $this->assertNotContains('ua_blacklist', $browserResult['reasons']);

        // 支付网关回调使用签名验真，不应因 PayPal、Stripe、Wise 或通用 HTTP 客户端 UA 被漏斗拦截。
        foreach ([
            'PayPal/1.0',
            'PayPal-WebHook/1.0',
            'Stripe/1.0 (+https://stripe.com/docs/webhooks)',
            'Wise-Webhook',
            'Go-http-client/1.1',
            'Java/17',
        ] as $userAgent) {
            $request = Request::create('/webhooks/payment', 'POST', [], [], [], [
                'REMOTE_ADDR' => '203.0.113.10',
                'HTTP_USER_AGENT' => $userAgent,
            ]);

            $result = $this->funnel()->evaluate($request, $this->ipResult());

            $this->assertSame('allow', $result['action'], $userAgent);
            $this->assertNotContains('ua_blacklist', $result['reasons'], $userAgent);
        }
    }

    /**
     * 频率限制命中时返回独立动作，调用方可按路由实现限速或展示模式降级。
     */
    public function test_rate_limit_has_priority_over_observe(): void
    {
        $this->setTrafficSetting('traffic_rate_limit_enabled', true);
        $this->setTrafficSetting('traffic_rate_limit_max', 1);
        $this->setTrafficSetting('traffic_rate_limit_decay', 60);
        $request = Request::create('/products', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']);

        $this->funnel()->evaluate($request, $this->ipResult());
        $result = $this->funnel()->evaluate($request, $this->ipResult());

        $this->assertSame('rate_limit', $result['action']);
        $this->assertContains('rate_limit_exceeded', $result['reasons']);
    }

    /**
     * 本地云网段命中时进入数据中心风险信号，不把单一云网段直接封禁。
     */
    public function test_cloud_range_is_datacenter_signal(): void
    {
        $intelligence = $this->intelligence([
            'cloud_provider' => 'AWS',
            'network_type'   => 'DATACENTER',
            'cloud_range'    => '198.51.100.0/24',
        ]);
        $request = Request::create('/products', 'GET', [], [], [], ['REMOTE_ADDR' => '198.51.100.8']);

        $result = (new TrafficFunnelService($intelligence))->evaluate($request, $this->ipResult());

        $this->assertSame('observe', $result['action']);
        $this->assertSame('AWS', $result['signals']['cloud_ip_range']['provider']);
        $this->assertContains('cloud_ip_range', $result['reasons']);
    }

    /**
     * 未知自动化流量可通过短窗口高频请求进入可解释的行为风险分支。
     */
    public function test_behavior_request_burst_generates_auditable_signal(): void
    {
        $this->setTrafficSetting('traffic_behavior_enabled', true);
        $this->setTrafficSetting('traffic_behavior_window', 300);
        $this->setTrafficSetting('traffic_behavior_max_requests', 1);
        $this->setTrafficSetting('traffic_behavior_max_routes', 100);
        $this->setTrafficSetting('traffic_behavior_invalid_cookie_max', 100);
        $this->setTrafficSetting('traffic_behavior_score', 20);
        $this->setTrafficSetting('traffic_challenge_threshold', 20);
        $request = Request::create('/new-route', 'GET', [], [], [], [
            'REMOTE_ADDR'     => '203.0.113.77',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
        ]);
        $ipResult = array_merge($this->ipResult(), ['ip' => '203.0.113.77']);

        $this->funnel()->evaluate($request, $ipResult);
        $result = $this->funnel()->evaluate($request, $ipResult);

        $this->assertSame('challenge', $result['action']);
        $this->assertContains('behavior_request_burst', $result['reasons']);
        $this->assertSame(2, $result['signals']['behavior_request_burst']['requests']);
    }

    /**
     * 匿名 IP、云网段和 ASN 作为网络上下文，只在行为异常后形成组合风险信号。
     */
    public function test_network_intelligence_enhances_existing_behavior_signal(): void
    {
        $this->setTrafficSetting('traffic_behavior_enabled', true);
        $this->setTrafficSetting('traffic_behavior_window', 300);
        $this->setTrafficSetting('traffic_behavior_max_requests', 1);
        $this->setTrafficSetting('traffic_behavior_max_routes', 100);
        $this->setTrafficSetting('traffic_behavior_invalid_cookie_max', 100);
        $this->setTrafficSetting('traffic_behavior_score', 20);
        $this->setTrafficSetting('traffic_challenge_threshold', 50);
        $request = Request::create('/network-behavior', 'GET', [], [], [], [
            'REMOTE_ADDR'     => '203.0.113.66',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
        ]);
        $ipResult = array_merge($this->ipResult(), ['ip' => '203.0.113.66']);
        $intelligence = $this->intelligence([
            'asn'            => 64512,
            'anonymous'      => true,
            'cloud_provider' => 'AWS',
            'network_type'   => 'DATACENTER',
        ]);
        $service = new TrafficFunnelService($intelligence);

        $service->evaluate($request, $ipResult);
        $result = $service->evaluate($request, $ipResult);

        $this->assertContains('behavior_request_burst', $result['reasons']);
        $this->assertContains('behavior_network_activity', $result['reasons']);
        $this->assertSame(64512, $result['signals']['behavior_network_activity']['network_context']['asn']);
        $this->assertTrue($result['signals']['behavior_network_activity']['network_context']['anonymous_ip']);
        $this->assertSame('AWS', $result['signals']['behavior_network_activity']['network_context']['datacenter']['provider']);
    }

    /**
     * 人工可信状态只忽略行为计分，仍保留其他规则和深度识别能力。
     */
    public function test_trusted_risk_profile_skips_behavior_signals_only(): void
    {
        $this->setTrafficSetting('traffic_behavior_enabled', true);
        $this->setTrafficSetting('traffic_behavior_max_requests', 1);
        $this->setTrafficSetting('traffic_behavior_score', 40);
        $ipResult = array_merge($this->ipResult(), ['ip' => '203.0.113.88']);
        $request = Request::create('/trusted-check', 'GET', [], [], [], [
            'REMOTE_ADDR'     => '203.0.113.88',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
        ]);
        $audit = new class extends TrafficRiskAuditService
        {
            public function profileStatus(string $ip): string
            {
                return 'trusted';
            }

            public function record(Request $request, array $result, bool $enabled, int $threshold): void
            {
            }
        };
        $funnel = new TrafficFunnelService($this->intelligence(), null, $audit);

        $funnel->evaluate($request, $ipResult);
        $result = $funnel->evaluate($request, $ipResult);

        $this->assertSame('allow', $result['action']);
        $this->assertSame('trusted', $result['signals']['manual_risk_status']);
        $this->assertNotContains('behavior_request_burst', $result['reasons']);
    }

    /**
     * 人工封禁档案在无凭据漏斗中优先转为封禁动作。
     */
    public function test_blocked_risk_profile_is_a_high_priority_signal(): void
    {
        $audit = new class extends TrafficRiskAuditService
        {
            public function profileStatus(string $ip): string
            {
                return 'blocked';
            }

            public function record(Request $request, array $result, bool $enabled, int $threshold): void
            {
            }
        };
        $request = Request::create('/products', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']);

        $result = (new TrafficFunnelService($this->intelligence(), null, $audit))->evaluate($request, $this->ipResult());

        $this->assertSame('block', $result['action']);
        $this->assertContains('manual_risk_block', $result['reasons']);
    }

    /**
     * 人工可疑结论作为软风险信号参与阈值计算，不直接替代静态黑名单。
     */
    public function test_suspicious_risk_profile_adds_a_soft_signal(): void
    {
        $audit = new class extends TrafficRiskAuditService
        {
            public function profileStatus(string $ip): string
            {
                return 'suspicious';
            }

            public function record(Request $request, array $result, bool $enabled, int $threshold): void
            {
            }
        };
        $request = Request::create('/products', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']);

        $result = (new TrafficFunnelService($this->intelligence(), null, $audit))->evaluate($request, $this->ipResult());

        $this->assertSame('observe', $result['action']);
        $this->assertContains('manual_risk_suspicious', $result['reasons']);
    }

    /**
     * 有效真实站 key 在国家、语言和 UA 等漏斗信号之前解析。
     */
    public function test_valid_key_bypasses_funnel_and_enters_real_mode(): void
    {
        $plainKey = 'funnel-test-key';
        config()->set('app.key', 'base64:' . base64_encode('funnel-test-secret'));
        config()->set('cyber_cloak.key_parameter', 'key');
        config()->set('cyber_cloak.cookie_name', 'beike_context');
        $this->setTrafficSetting('traffic_allowed_countries', 'CN');
        $this->setTrafficSetting('traffic_allowed_languages', 'zh-CN');
        config()->set('cyber_cloak.access_keys', [[
            'id'         => 'funnel-key-id',
            'hash'       => hash('sha256', $plainKey),
            'status'     => 'enabled',
            'expires_at' => null,
        ]]);
        config()->set('bk.plugin.cyber_cloak.access_keys', config('cyber_cloak.access_keys'));
        config()->set('cyber_cloak.ip_blacklist', '');
        config()->set('cyber_cloak.ip_whitelist', '');
        config()->set('cyber_cloak.trusted_proxy_ips', '');
        config()->set('cyber_cloak.cloudflare_enabled', true);

        $keys     = new AccessKeyService;
        $resolver = new CatalogResolver(
            $keys,
            new ContextTicketService($keys),
            new IpAccessService,
            null,
            new TrafficFunnelService($this->intelligence(['country' => 'CN'])),
        );
        $request = Request::create('/products?key=' . $plainKey, 'GET', [], [], [], [
            'REMOTE_ADDR'          => '203.0.113.10',
            'HTTP_ACCEPT_LANGUAGE' => 'zh-CN,zh;q=0.9',
            'HTTP_USER_AGENT'      => 'HeadlessChrome',
        ]);

        $result = $resolver->resolve($request);

        $this->assertSame('real', $result['mode']);
        $this->assertSame('query_key', $result['reason']);
        $this->assertSame('access_key', $result['risk']['stage']);

        $blockedResolver = new CatalogResolver(
            $keys,
            new ContextTicketService($keys),
            new IpAccessService,
            null,
            new TrafficFunnelService($this->intelligence(['country' => 'US'])),
        );
        $blockedRequest = Request::create('/products?key=' . $plainKey, 'GET', [], [], [], [
            'REMOTE_ADDR'          => '203.0.113.10',
            'HTTP_ACCEPT_LANGUAGE' => 'zh-CN,zh;q=0.9',
            'HTTP_USER_AGENT'      => 'Mozilla/5.0',
        ]);
        $blocked = $blockedResolver->resolve($blockedRequest);

        $this->assertSame('real', $blocked['mode']);
        $this->assertSame('query_key', $blocked['reason']);
        $this->assertSame('access_key', $blocked['risk']['stage']);
    }

    /**
     * 构造稳定的 IP 判断结果，避免测试依赖数据库或外部服务。
     *
     * @return array{ip:string,allowed:bool,blacklisted:bool,whitelisted:bool,reputation:bool}
     */
    private function ipResult(): array
    {
        return [
            'ip'                   => '203.0.113.10',
            'allowed'              => true,
            'blacklisted'          => false,
            'whitelisted'          => false,
            'reputation' => false,
        ];
    }

    /**
     * 同时覆盖环境配置和后台设置缓存，隔离本机数据库中的插件规则。
     */
    private function setTrafficSetting(string $name, mixed $value): void
    {
        config()->set("cyber_cloak.{$name}", $value);
        config()->set("bk.plugin.cyber_cloak.{$name}", $value);
    }

    /**
     * 创建使用可控 MaxMind 结果的漏斗服务。
     */
    private function funnel(): TrafficFunnelService
    {
        return new TrafficFunnelService($this->intelligence());
    }

    /**
     * 用内存结果模拟本地 MMDB 查询，验证漏斗组合规则而不读取真实数据库。
     */
    private function intelligence(array $override = []): MaxMindIpIntelligence
    {
        $value = array_merge([
            'country'      => null,
            'asn'          => null,
            'organization' => null,
            'anonymous'    => null,
            'cloud_provider' => null,
            'network_type' => 'UNKNOWN',
            'cloud_range'  => null,
            'available'    => false,
            'source'       => 'fixture',
        ], $override);

        return new class($value) extends MaxMindIpIntelligence
        {
            public function __construct(private readonly array $value)
            {
            }

            public function lookup(string $ip): array
            {
                return $this->value;
            }
        };
    }
}
