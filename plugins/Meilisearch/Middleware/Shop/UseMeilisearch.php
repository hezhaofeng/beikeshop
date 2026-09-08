<?php

namespace Plugin\Meilisearch\Middleware\Shop;

use Closure;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Plugin\Meilisearch\Controllers\SearchController;
use Plugin\Meilisearch\Controllers\SuggestController;
use Plugin\Meilisearch\Controllers\TrendingController;
use Plugin\Meilisearch\Services\Settings;

/**
 * 接管前台搜索和搜索联想；任何异常都会按配置回退到原有的 MySQL 查询。
 */
class UseMeilisearch
{
    public function handle(Request $request, Closure $next)
    {
        if (! Settings::searchEnabled()) {
            return $next($request);
        }

        if ($request->routeIs('shop.products.search')) {
            return $this->takeOver($request, $next, SearchController::class, trim((string) $request->get('keyword')));
        }

        if ($request->routeIs('shop.products.autocomplete')) {
            return $this->takeOver($request, $next, SuggestController::class, trim((string) $request->get('name')));
        }

        if ($request->routeIs('shop.products.hot-products')) {
            return $this->takeOver($request, $next, TrendingController::class, '', true);
        }

        return $next($request);
    }

    /**
     * 关键词为空或带属性筛选时交还给 MySQL：属性筛选没有下推到索引，走原路径才能保证结果正确。
     */
    private function takeOver(Request $request, Closure $next, string $controller, string $keyword, bool $allowEmpty = false)
    {
        if ((! $allowEmpty && $keyword === '') || $request->filled('attr')) {
            return $next($request);
        }

        try {
            $response = app($controller)($request);

            // 如果控制器返回 Responsable 对象（如 View），转换成真正的 Response 对象
            // 确保后续中间件（如 VerifyCsrfToken）能正常处理
            if ($response instanceof Responsable) {
                $response = $response->toResponse($request);
            }

            // 如果仍然不是 Response 对象（例如 View 对象未实现 Responsable），
            // 手动转换成 Response 对象
            if (!$response instanceof \Illuminate\Http\Response &&
                !$response instanceof \Symfony\Component\HttpFoundation\Response) {
                $response = response($response);
            }

            return $response;
        } catch (\Throwable $e) {
            Log::warning('Meilisearch 搜索失败', [
                'message' => $e->getMessage(),
                'route'   => $request->route()?->getName(),
                'locale'  => locale(),
                'keyword' => mb_substr($keyword, 0, 100),
            ]);

            if (Settings::mysqlFallback()) {
                return $next($request);
            }

            throw $e;
        }
    }
}
