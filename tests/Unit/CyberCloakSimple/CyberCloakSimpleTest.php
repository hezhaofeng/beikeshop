<?php

namespace Tests\Unit\CyberCloakSimple;

use Beike\Models\Product;
use Illuminate\Http\Request;
use Plugin\CyberCloakSimple\Services\AccessKeyService;
use Plugin\CyberCloakSimple\Services\AccessResolver;
use Plugin\CyberCloakSimple\Services\ContextTicketService;
use Plugin\CyberCloakSimple\Services\CountryResolver;
use Plugin\CyberCloakSimple\Services\MappingService;
use Plugin\CyberCloakSimple\Services\SettingService;
use Plugin\CyberCloakSimple\Services\StoreContext;
use Tests\TestCase;

/**
 * CyberCloak Simple 的凭据、访问规则和单库映射测试。
 */
class CyberCloakSimpleTest extends TestCase
{
    private const PLAIN_KEY = 'simple-test-key';

    /**
     * 配置测试用签名密钥和隔离的内存插件设置。
     */
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:' . base64_encode('cyber-cloak-simple-test-secret'));
        config()->set('cyber_cloak_simple', [
            'key_parameter'           => 'key',
            'cookie_name'             => 'beike_simple_context',
            'cookie_lifetime_minutes' => 60,
            'cookie_secure'           => false,
            'cookie_http_only'        => true,
            'cookie_same_site'        => 'lax',
            'cookie_path'             => '/',
            'prevent_shared_cache'    => true,
            'country_header'          => 'CF-IPCountry',
            'trusted_proxy_ips'       => '127.0.0.1',
        ]);
        config()->set('bk.plugin.cyber_cloak_simple.access_keys', [[
            'id'         => 'simple-key-id',
            'hash'       => hash('sha256', self::PLAIN_KEY),
            'status'     => 'enabled',
            'expires_at' => null,
        ]]);
        config()->set('bk.plugin.cyber_cloak_simple.ip_whitelist', '');
        config()->set('bk.plugin.cyber_cloak_simple.ip_blacklist', '');
        config()->set('bk.plugin.cyber_cloak_simple.country_whitelist', '');
        config()->set('bk.plugin.cyber_cloak_simple.trusted_proxy_ips', '127.0.0.1');
        config()->set('bk.plugin.cyber_cloak_simple.language_whitelist', '');
        config()->set('bk.plugin.cyber_cloak_simple.product_mappings', [
            ['real_id' => 1, 'public_id' => 101],
        ]);
        config()->set('bk.plugin.cyber_cloak_simple.category_mappings', [
            ['real_id' => 10, 'public_id' => 110],
        ]);
    }

    /**
     * 有效 query key 进入真实模式并被移除，响应结果只包含签名 Cookie。
     */
    public function test_query_key_enters_real_mode_and_issues_signed_cookie(): void
    {
        $request    = Request::create('/products?key=' . self::PLAIN_KEY, 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $resolution = $this->resolver()->resolve($request);

        $this->assertSame(StoreContext::REAL, $resolution['mode']);
        $this->assertSame('query_key', $resolution['reason']);
        $this->assertNull($request->query('key'));
        $this->assertSame('set', $resolution['cookie']['action']);
        $this->assertStringNotContainsString(self::PLAIN_KEY, $resolution['cookie']['value']);
    }

    /**
     * IP 黑名单优先于有效 key，并清除旧 Cookie。
     */
    public function test_ip_blacklist_has_priority_over_key(): void
    {
        config()->set('bk.plugin.cyber_cloak_simple.ip_blacklist', '127.0.0.1');
        $request = Request::create(
            '/products?key=' . self::PLAIN_KEY,
            'GET',
            [],
            ['beike_simple_context' => 'stale'],
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
        );

        $resolution = $this->resolver()->resolve($request);

        $this->assertSame(StoreContext::PUBLIC, $resolution['mode']);
        $this->assertSame('ip_blacklist', $resolution['reason']);
        $this->assertSame('forget', $resolution['cookie']['action']);
    }

    /**
     * 地区和语言白名单分别按客户端 IP 地域和无凭据浏览器语言生效。
     */
    public function test_country_and_language_whitelists_are_checked_for_public_requests(): void
    {
        config()->set('bk.plugin.cyber_cloak_simple.country_whitelist', ['CN']);
        config()->set('bk.plugin.cyber_cloak_simple.language_whitelist', ['zh-CN']);
        $request = Request::create('/products', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $request->headers->set('CF-IPCountry', 'CN');
        $request->headers->set('Accept-Language', 'zh-CN,zh;q=0.9');

        $resolution = $this->resolver()->resolve($request);

        $this->assertSame(StoreContext::PUBLIC, $resolution['mode']);
        $this->assertSame('no_credentials', $resolution['reason']);
        $this->assertFalse($resolution['blocked']);
    }

    /**
     * 未命中地区白名单的展示请求返回阻断标记，供中间件统一输出 404。
     */
    public function test_non_matching_country_is_blocked(): void
    {
        config()->set('bk.plugin.cyber_cloak_simple.country_whitelist', ['US']);
        $request = Request::create('/products', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $request->headers->set('CF-IPCountry', 'CN');

        $resolution = $this->resolver()->resolve($request);

        $this->assertSame('country_not_whitelisted', $resolution['reason']);
        $this->assertTrue($resolution['blocked']);
    }

    /**
     * GeoIP 不可用时，直连请求伪造地区头不能绕过地区白名单。
     */
    public function test_country_header_is_ignored_for_untrusted_proxy(): void
    {
        config()->set('bk.plugin.cyber_cloak_simple.trusted_proxy_ips', '198.51.100.0/24');
        $request = Request::create('/products', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $request->headers->set('CF-IPCountry', 'CN');

        $this->assertNull((new CountryResolver(new SettingService))->resolve($request));
    }

    /**
     * 多个国家/地区可同时允许，且地域规则先于携带 key 的真实访问生效。
     */
    public function test_multiple_countries_restrict_key_access_by_client_ip_region(): void
    {
        config()->set('bk.plugin.cyber_cloak_simple.country_whitelist', ['CN', 'US']);
        $allowedRequest = Request::create('/products?key=' . self::PLAIN_KEY, 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $allowedRequest->headers->set('CF-IPCountry', 'US');

        $allowed = $this->resolver()->resolve($allowedRequest);

        $this->assertSame(StoreContext::REAL, $allowed['mode']);
        config()->set('bk.plugin.cyber_cloak_simple.country_whitelist', ['US']);
        $blockedRequest = Request::create('/products?key=' . self::PLAIN_KEY, 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $blockedRequest->headers->set('CF-IPCountry', 'CN');

        $blocked = $this->resolver()->resolve($blockedRequest);

        $this->assertSame('country_not_whitelisted', $blocked['reason']);
        $this->assertTrue($blocked['blocked']);
    }

    /**
     * 配置中的 value 会即时摘要化，未来过期时间可用，过期记录立即失效。
     */
    public function test_access_key_value_respects_expiry(): void
    {
        config()->set('bk.plugin.cyber_cloak_simple.access_keys', [[
            'id'         => 'value-key-id',
            'value'      => self::PLAIN_KEY,
            'status'     => 'enabled',
            'expires_at' => now()->addMinutes(10)->toIso8601String(),
        ]]);
        $validRequest = Request::create('/products?key=' . self::PLAIN_KEY, 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);

        $this->assertSame(StoreContext::REAL, $this->resolver()->resolve($validRequest)['mode']);

        config()->set('bk.plugin.cyber_cloak_simple.access_keys', [[
            'id'         => 'expired-value-key-id',
            'value'      => self::PLAIN_KEY,
            'status'     => 'enabled',
            'expires_at' => now()->subMinute()->toIso8601String(),
        ]]);
        $expiredRequest = Request::create('/products?key=' . self::PLAIN_KEY, 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);

        $this->assertSame(StoreContext::PUBLIC, $this->resolver()->resolve($expiredRequest)['mode']);
    }

    /**
     * 首页商品模块和分类导航链接直接复用商品、分类映射。
     */
    public function test_product_category_and_homepage_mappings(): void
    {
        // 历史首页覆盖配置不再参与解析，首页始终复用商品与分类统一映射。
        config()->set('bk.plugin.cyber_cloak_simple.homepage_mappings', [
            ['type' => 'product', 'real_id' => 1, 'public_id' => 201],
        ]);
        $context = new StoreContext;
        $context->activate(['mode' => StoreContext::PUBLIC]);
        $mapping = new MappingService($context, new SettingService);

        $this->assertSame([101], $mapping->mapHomepageProductIds([1]));
        $this->assertStringContainsString('/products/1', $mapping->mapUrl([
            'type'  => 'product',
            'value' => 1,
            'url'   => '',
        ])['url']);
        $categoryUrl = $mapping->mapUrl([
            'type'  => 'category',
            'value' => 10,
            'url'   => '',
        ]);
        $this->assertTrue($categoryUrl['handled']);
        $this->assertStringContainsString('/categories/10', $categoryUrl['url']);
        $this->assertSame('', $mapping->mapUrl([
            'type'  => 'category',
            'value' => 999,
            'url'   => '/categories/999',
        ])['url']);

        // 直接传入模型时交由 model.product.url Hook 处理，避免对象被错误转换为映射源 ID。
        $productLink = ['type' => 'product', 'value' => new Product, 'url' => ''];
        $this->assertSame($productLink, $mapping->mapUrl($productLink));
    }

    /**
     * 多选国家字段和一键映射面板必须可由默认插件表单与 Blade 正常编译。
     */
    public function test_configuration_exposes_multi_country_and_one_click_mapping_panel(): void
    {
        $columns = collect(require base_path('plugins/CyberCloakSimple/columns.php'))->keyBy('name');
        $view    = file_get_contents(base_path('plugins/CyberCloakSimple/Views/admin/mapping.blade.php'));

        $this->assertSame('select-multiple', $columns->get('country_whitelist')['type']);
        $this->assertSame('nullable|array', $columns->get('country_whitelist')['rules']);
        $this->assertFalse($columns->has('homepage_mappings'));
        $this->assertStringContainsString('商品映射一键处理', $view);
        $this->assertStringContainsString('分类映射一键处理', $view);
        $this->assertStringContainsString('cyber_cloak_simple.mappings.product', $view);
        $this->assertNotSame('', app('blade.compiler')->compileString($view));
    }

    /**
     * 构造纯逻辑访问解析器，避免测试依赖数据库连接。
     */
    private function resolver(): AccessResolver
    {
        $settings = new SettingService;
        $keys     = new AccessKeyService($settings);

        return new AccessResolver($keys, new ContextTicketService($keys), $settings, new CountryResolver($settings));
    }
}
