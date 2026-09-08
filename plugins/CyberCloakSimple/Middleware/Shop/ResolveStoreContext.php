<?php

namespace Plugin\CyberCloakSimple\Middleware\Shop;

use Closure;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Plugin\CyberCloakSimple\Services\AccessResolver;
use Plugin\CyberCloakSimple\Services\SettingService;
use Plugin\CyberCloakSimple\Services\StoreContext;

/**
 * 在前台路由模型绑定前设置简单访问上下文，并统一写入或清理 Cookie。
 */
class ResolveStoreContext
{
    /**
     * 注入请求解析、上下文和 Cookie 服务。
     */
    public function __construct(
        private readonly AccessResolver $resolver,
        private readonly StoreContext $context,
        private readonly SettingService $settings,
        private readonly CookieJar $cookies,
    ) {
    }

    /**
     * 处理当前前台请求；地区或语言白名单不通过时直接返回 404，不运行控制器。
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $resolution = $this->resolver->resolve($request);
        $this->context->activate($resolution);

        try {
            $response = $resolution['blocked'] ? response('', 404) : $next($request);
            $this->applyCookie($response, $resolution['cookie']);
            $this->applyCacheHeaders($response);

            return $response;
        } finally {
            // 常驻进程请求结束后必须恢复，避免后续请求继承真实模式。
            $this->context->reset();
        }
    }

    /**
     * 根据解析结果签发或清除签名上下文 Cookie。
     */
    private function applyCookie(mixed $response, array $cookie): void
    {
        if (! is_object($response) || ! isset($response->headers) || $response->headers === null) {
            return;
        }

        $name   = (string) $this->settings->value('cookie_name', config('cyber_cloak_simple.cookie_name'));
        $path   = (string) $this->settings->value('cookie_path', config('cyber_cloak_simple.cookie_path', '/'));
        $action = (string) ($cookie['action'] ?? 'none');
        if ($action === 'forget') {
            $response->headers->setCookie($this->cookies->forget($name, $path));

            return;
        }
        if ($action !== 'set') {
            return;
        }

        $response->headers->setCookie($this->cookies->make(
            $name,
            (string) ($cookie['value'] ?? ''),
            (int) ($cookie['minutes'] ?? 0),
            $path,
            config('session.domain'),
            (bool) $this->settings->value('cookie_secure', config('cyber_cloak_simple.cookie_secure', true)),
            (bool) $this->settings->value('cookie_http_only', config('cyber_cloak_simple.cookie_http_only', true)),
            false,
            (string) $this->settings->value('cookie_same_site', config('cyber_cloak_simple.cookie_same_site', 'lax')),
        ));
    }

    /**
     * 真实和展示内容共享域名时禁止共享缓存混用两种响应。
     */
    private function applyCacheHeaders(mixed $response): void
    {
        if (! $this->settings->value('prevent_shared_cache', config('cyber_cloak_simple.prevent_shared_cache', true))
            || ! is_object($response) || ! isset($response->headers) || $response->headers === null) {
            return;
        }

        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Vary', 'Cookie, Accept-Language, CF-IPCountry');
    }
}
