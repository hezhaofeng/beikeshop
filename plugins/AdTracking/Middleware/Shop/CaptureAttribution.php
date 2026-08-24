<?php

namespace Plugin\AdTracking\Middleware\Shop;

use Closure;
use Illuminate\Http\Request;
use Plugin\AdTracking\Services\AttributionService;

class CaptureAttribution
{
    /**
     * 每次进入店铺请求时捕获允许的广告参数，不保存完整 Query，降低隐私和存储风险。
     */
    public function handle(Request $request, Closure $next): mixed
    {
        AttributionService::capture($request);

        return $next($request);
    }
}
