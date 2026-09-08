<?php

namespace Tests\Unit\CyberCloak;

use App\Http\Middleware\ShareViewData;
use Beike\Models\Category;
use Beike\Models\Page;
use Beike\Models\Product;
use Illuminate\Http\Request;
use Plugin\CyberCloak\Middleware\Shop\ResolveStoreContext;
use Plugin\CyberCloak\Services\AccessKeyService;
use Plugin\CyberCloak\Services\CatalogResolver;
use Plugin\CyberCloak\Services\CatalogRouteService;
use Plugin\CyberCloak\Services\ContextTicketService;
use Plugin\CyberCloak\Services\IpAccessService;
use Plugin\CyberCloak\Services\StoreContext;
use Tests\TestCase;

class CatalogResolverTest extends TestCase
{
    private const PLAIN_KEY = 'local-test-key';

    /**
     * 初始化阶段一测试所需的应用配置和摘要 key。
     */
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:' . base64_encode('cyber-cloak-test-secret'));
        config()->set('cyber_cloak', [
            'key_parameter'             => 'key',
            'cookie_name'               => 'beike_context',
            'cookie_lifetime_minutes'   => 60,
            'cookie_refresh_minutes'    => 1,
            'cookie_secure'             => true,
            'cookie_http_only'          => true,
            'cookie_same_site'          => 'lax',
            'cookie_path'               => '/',
            'prevent_shared_cache'      => true,
            'access_keys'               => [[
                'id'         => 'test-key-id',
                'hash'       => hash('sha256', self::PLAIN_KEY),
                'status'     => 'enabled',
                'expires_at' => null,
            ]],
            'ip_whitelist'       => '',
            'ip_blacklist'       => '',
            'trusted_proxy_ips'  => '',
            'cloudflare_enabled' => true,
            'connections'        => [
                'real'   => 'mysql',
                'public' => 'catalog_public',
            ],
        ]);
        config()->set('bk.plugin.cyber_cloak.access_keys', config('cyber_cloak.access_keys'));
        config()->set('bk.plugin.cyber_cloak.ip_whitelist', '');
        config()->set('bk.plugin.cyber_cloak.ip_blacklist', '');
        config()->set('bk.plugin.cyber_cloak.trusted_proxy_ips', '');
    }

    /**
     * 有效 query key 应立即进入真实模式，并从后续请求参数中移除 key。
     */
    public function test_valid_query_key_enters_real_mode_without_redirect(): void
    {
        $request    = Request::create('/products?key=' . self::PLAIN_KEY, 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $resolution = $this->resolver()->resolve($request);

        $this->assertSame('real', $resolution['mode']);
        $this->assertSame('query_key', $resolution['reason']);
        $this->assertSame('test-key-id', $resolution['key_id']);
        $this->assertNull($request->query('key'));
        $this->assertSame('set', $resolution['cookie']['action']);
        $this->assertStringNotContainsString(self::PLAIN_KEY, $resolution['cookie']['value']);
    }

    /**
     * 后台生成的签名分享链接应与原始 key 一样进入真实模式。
     */
    public function test_valid_signed_share_link_enters_real_mode_without_redirect(): void
    {
        $accessKeys = new AccessKeyService;
        $ticket     = (new ContextTicketService($accessKeys))->issue($accessKeys->findValid(self::PLAIN_KEY));
        $request    = Request::create('/products?key=' . rawurlencode($ticket), 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $resolution = $this->resolver()->resolve($request);

        $this->assertSame('real', $resolution['mode']);
        $this->assertSame('query_key', $resolution['reason']);
        $this->assertSame('test-key-id', $resolution['key_id']);
        $this->assertNull($request->query('key'));
    }

    /**
     * 有效 Cookie 应维持真实模式，并在续期窗口到达后重新签发票据。
     */
    public function test_valid_cookie_enters_real_mode_and_refreshes(): void
    {
        $keyService            = new AccessKeyService;
        $ticketService         = new ContextTicketService($keyService);
        $ticket                = $ticketService->issue($keyService->findValid(self::PLAIN_KEY));
        [$payload, $signature] = explode('.', $ticket, 2);
        $payloadData           = json_decode(base64_decode(strtr($payload, '-_', '+/') . '=='), true);
        $payloadData['issued'] = time() - 120;
        $payload               = rtrim(strtr(base64_encode(json_encode($payloadData)), '+/', '-_'), '=');
        $signature             = hash_hmac('sha256', $payload, 'cyber-cloak-test-secret');
        $ticket                = $payload . '.' . $signature;
        $request               = Request::create('/products', 'GET', [], ['beike_context' => $ticket], [], ['REMOTE_ADDR' => '127.0.0.1']);

        $resolution = $this->resolver()->resolve($request);

        $this->assertSame('real', $resolution['mode']);
        $this->assertSame('cookie', $resolution['reason']);
        $this->assertSame('set', $resolution['cookie']['action']);
    }

    /**
     * 黑名单必须优先于有效 key，且现有真实模式 Cookie 应被清理。
     */
    public function test_blacklist_has_priority_over_valid_key(): void
    {
        config()->set('bk.plugin.cyber_cloak.ip_blacklist', '127.0.0.1');
        $request = Request::create('/products?key=' . self::PLAIN_KEY, 'GET', [], ['beike_context' => 'stale-ticket'], [], ['REMOTE_ADDR' => '127.0.0.1']);

        $resolution = $this->resolver()->resolve($request);

        $this->assertSame('public', $resolution['mode']);
        $this->assertSame('ip_blacklist', $resolution['reason']);
        $this->assertSame('forget', $resolution['cookie']['action']);
        $this->assertNull($request->query('key'));
    }

    /**
     * 非空白名单下，未命中的地址应回到展示模式。
     */
    public function test_non_matching_whitelist_enters_public_mode(): void
    {
        config()->set('bk.plugin.cyber_cloak.ip_whitelist', '10.0.0.0/8');
        $request = Request::create('/products?key=' . self::PLAIN_KEY, 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);

        $resolution = $this->resolver()->resolve($request);

        $this->assertSame('public', $resolution['mode']);
        $this->assertSame('ip_not_whitelisted', $resolution['reason']);
    }

    /**
     * 服务端过期时间到达后，即使 query key 正确也必须回到展示模式。
     */
    public function test_expired_key_enters_public_mode(): void
    {
        $expiredRecord = [[
            'id'         => 'expired-key-id',
            'hash'       => hash('sha256', self::PLAIN_KEY),
            'status'     => 'enabled',
            'expires_at' => now()->subMinute()->toIso8601String(),
        ]];
        config()->set('bk.plugin.cyber_cloak.access_keys', $expiredRecord);

        $request    = Request::create('/products?key=' . self::PLAIN_KEY, 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $resolution = $this->resolver()->resolve($request);

        $this->assertSame('public', $resolution['mode']);
        $this->assertSame('invalid_key', $resolution['reason']);
    }

    /**
     * Cloudflare 头只有在来源代理命中可信网段时才参与真实 IP 判断。
     */
    public function test_cloudflare_ip_is_used_only_from_trusted_proxy(): void
    {
        config()->set('bk.plugin.cyber_cloak.trusted_proxy_ips', '10.0.0.0/8');
        $request = Request::create('/products', 'GET', [], [], [], [
            'REMOTE_ADDR'           => '10.20.30.40',
            'HTTP_CF_CONNECTING_IP' => '203.0.113.9',
        ]);

        $result = (new IpAccessService)->evaluate($request);

        $this->assertSame('203.0.113.9', $result['ip']);
    }

    /**
     * 中间件应在控制器执行期间暴露真实模式，并在响应完成后恢复展示模式。
     */
    public function test_middleware_sets_cookie_and_resets_context(): void
    {
        $context    = new StoreContext;
        $request    = Request::create('/products?key=' . self::PLAIN_KEY, 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $middleware = new ResolveStoreContext($this->resolver(), $context, app('cookie'));

        $response = $middleware->handle($request, function () use ($context) {
            $this->assertTrue($context->isReal());

            return response('ok');
        });

        $cookies       = $response->headers->getCookies();
        $contextCookie = collect($cookies)->first(fn ($cookie) => $cookie->getName() === 'beike_context');
        $this->assertNotNull($contextCookie);
        $this->assertTrue($contextCookie->isSecure());
        $this->assertTrue($contextCookie->isHttpOnly());
        $this->assertSame('lax', $contextCookie->getSameSite());
        $this->assertTrue($context->isPublic());
        $this->assertNull($request->query('key'));
    }

    /**
     * 纯 HTTP 部署显式关闭 Secure 后，浏览器才能在后续分类页继续携带真实站上下文 Cookie。
     */
    public function test_middleware_allows_http_cookie_when_secure_flag_is_disabled(): void
    {
        config()->set('cyber_cloak.cookie_secure', false);

        $context    = new StoreContext;
        $request    = Request::create('/categories/100019?key=' . self::PLAIN_KEY, 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $middleware = new ResolveStoreContext($this->resolver(), $context, app('cookie'));

        $response = $middleware->handle($request, fn () => response('ok'));
        $cookie   = collect($response->headers->getCookies())
            ->first(fn ($item) => $item->getName() === 'beike_context');

        $this->assertNotNull($cookie);
        $this->assertFalse($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
    }

    /**
     * Horizon 使用独立中间件组，避免后台路由执行前台商品上下文解析。
     */
    public function test_horizon_group_excludes_store_context_middleware(): void
    {
        $groups = app('router')->getMiddlewareGroups();

        $this->assertArrayHasKey('horizon', $groups);
        $this->assertNotContains(ResolveStoreContext::class, $groups['horizon']);
    }

    /**
     * 商品库上下文必须早于视图共享，确保首页导航按当前模式生成。
     */
    public function test_store_context_runs_before_shop_view_data_sharing(): void
    {
        $middlewares   = app('router')->getMiddlewareGroups()['shop'];
        $contextIndex  = array_search(ResolveStoreContext::class, $middlewares, true);
        $viewDataIndex = array_search(ShareViewData::class, $middlewares, true);

        $this->assertNotFalse($contextIndex);
        $this->assertNotFalse($viewDataIndex);
        $this->assertLessThan($viewDataIndex, $contextIndex);
    }

    /**
     * 商品库查询必须随当前请求模式选择显式连接。
     */
    public function test_catalog_resolver_selects_connection_from_store_context(): void
    {
        $context = new StoreContext;
        app()->instance(StoreContext::class, $context);
        $context->activate(['mode' => StoreContext::PUBLIC]);
        $resolver = $this->resolver();

        $this->assertSame('catalog_public', $resolver->connectionName());
        $this->assertSame('mysql', $resolver->connectionName(StoreContext::REAL));

        $context->reset();
    }

    /**
     * 商品库表名使用白名单，避免调用方把任意表名传入跨库查询。
     */
    public function test_catalog_resolver_rejects_unknown_table(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resolver()->table('orders');
    }

    /**
     * 商品模型只有在请求上下文激活时切换展示库，避免后台读取展示库。
     */
    public function test_catalog_models_switch_connection_only_for_active_context(): void
    {
        $context = new StoreContext;
        app()->instance(StoreContext::class, $context);
        $context->activate(['mode' => StoreContext::PUBLIC]);

        $this->assertSame('catalog_public', (new Product)->getConnectionName());

        $context->reset();

        $this->assertSame('mysql', (new Product)->getConnection()->getName());
    }

    /**
     * 页尾链接打开的页面及其商品关联保留在主库，不能查询展示库不存在的 page_products 表。
     */
    public function test_page_product_relation_stays_on_main_connection_in_public_mode(): void
    {
        $context = new StoreContext;
        app()->instance(StoreContext::class, $context);
        $context->activate(['mode' => StoreContext::PUBLIC]);

        $relation = (new Page)->products();

        $this->assertSame('mysql', $relation->getQuery()->getConnection()->getName());
        $this->assertStringContainsString('page_products', $relation->toBase()->toSql());

        $context->reset();
    }

    /**
     * 已解析的商品和分类必须沿用原始数字 URL 身份。
     */
    public function test_resolved_catalog_models_keep_source_url_id(): void
    {
        $product = new Product;
        $product->setAttribute('source_url_id', 123);
        $category = new Category;
        $category->setAttribute('source_url_id', 8);
        $service = new CatalogRouteService;

        $this->assertSame(123, $service->urlIdForProduct($product));
        $this->assertSame(8, $service->urlIdForCategory($category));
    }

    /**
     * 构造不依赖插件启用状态的解析器，便于单元测试阶段一纯逻辑。
     */
    private function resolver(): CatalogResolver
    {
        $accessKeys = new AccessKeyService;

        return new CatalogResolver(
            $accessKeys,
            new ContextTicketService($accessKeys),
            new IpAccessService,
        );
    }
}
