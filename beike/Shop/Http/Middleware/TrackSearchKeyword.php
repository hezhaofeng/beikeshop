<?php

namespace Beike\Shop\Http\Middleware;

use Beike\Services\SearchKeywordService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TrackSearchKeyword
{
    public function __construct(private readonly SearchKeywordService $keywords)
    {
    }

    /**
     * 放在 Meilisearch 接管中间件之外，确保原生搜索、Meilisearch 和 MySQL 回退都只统计一次。
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if (! $request->routeIs('shop.products.search') || ! $this->isSuccessful($response)) {
            return $response;
        }

        try {
            $this->keywords->record($request);
        } catch (Throwable $e) {
            // 搜索统计是辅助能力，迁移未执行或存储故障都不能影响搜索结果。
            Log::warning('搜索关键词统计失败', [
                'message' => $e->getMessage(),
                'locale'  => locale(),
            ]);
        }

        return $response;
    }

    private function isSuccessful(mixed $response): bool
    {
        return ! $response instanceof Response || $response->getStatusCode() < 400;
    }
}
