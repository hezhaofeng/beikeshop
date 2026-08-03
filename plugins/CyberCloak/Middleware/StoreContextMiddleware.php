<?php

namespace Plugin\CyberCloak\Middleware;

use Closure;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Plugin\CyberCloak\Services\CatalogResolver;
use Plugin\CyberCloak\Services\StoreContext;

abstract class StoreContextMiddleware
{
    public function __construct(
        private readonly CatalogResolver $resolver,
        private readonly StoreContext $context,
        private readonly CookieJar $cookies,
    ) {
    }

    /**
     * 在路由模型绑定前建立上下文，并在请求结束后恢复默认状态。
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $resolution = $this->resolver->resolve($request);
        $this->context->activate($resolution);

        try {
            $response = $next($request);
            $this->applyCookie($response, $resolution['cookie']);
            $this->applyCacheHeaders($response);

            return $response;
        } finally {
            // 即使控制器抛出异常，也必须清除常驻进程中的请求状态。
            $this->context->reset();
        }
    }

    /**
     * 为真实模式签发或清理上下文 Cookie。
     */
    private function applyCookie(mixed $response, array $cookie): void
    {
        if (! is_object($response) || ! isset($response->headers)) {
            return;
        }

        $action = $cookie['action'] ?? 'none';
        if ($action === 'none') {
            return;
        }

        $name = (string) config('cyber_cloak.cookie_name', 'beike_context');
        if ($action === 'forget') {
            $response->headers->setCookie($this->cookies->forget($name, config('cyber_cloak.cookie_path', '/')));

            return;
        }

        $response->headers->setCookie($this->cookies->make(
            $name,
            (string) ($cookie['value'] ?? ''),
            (int) ($cookie['minutes'] ?? 0),
            config('cyber_cloak.cookie_path', '/'),
            config('session.domain'),
            (bool) config('cyber_cloak.cookie_secure', true),
            (bool) config('cyber_cloak.cookie_http_only', true),
            false,
            config('cyber_cloak.cookie_same_site', 'lax')
        ));
    }

    /**
     * 防止共享缓存把真实模式页面返回给展示模式请求。
     */
    private function applyCacheHeaders(mixed $response): void
    {
        if (! config('cyber_cloak.prevent_shared_cache', true) || ! is_object($response) || ! isset($response->headers)) {
            return;
        }

        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Vary', 'Cookie, CF-Connecting-IP');
    }
}
