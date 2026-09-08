<?php

namespace Tests\Unit\SearchKeyword;

use Beike\Services\SearchKeywordService;
use Beike\Shop\Http\Middleware\TrackSearchKeyword;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;

class SearchKeywordServiceTest extends TestCase
{
    public function test_keyword_normalization_cleans_whitespace_and_control_characters(): void
    {
        $service = new SearchKeywordService();

        $this->assertSame('Phone Case XL', $service->normalizeKeyword("  Phone\tCase\nXL  "));
        $this->assertSame('手机 壳', $service->normalizeKeyword('手机　　壳'));
    }

    public function test_keyword_normalization_limits_storage_length(): void
    {
        $service = new SearchKeywordService();

        $this->assertSame(100, mb_strlen($service->normalizeKeyword(str_repeat('搜', 120))));
    }

    public function test_search_tracking_is_registered_in_shop_middleware_group(): void
    {
        $request = Request::create('/products/search');
        $route   = app('router')->getRoutes()->match($request);

        $this->assertContains(TrackSearchKeyword::class, app('router')->gatherRouteMiddleware($route));
    }

    public function test_record_ignores_pagination_and_robots_before_touching_storage(): void
    {
        $service = new SearchKeywordService();

        $pagination = Request::create('/products/search?keyword=case&page=2', 'GET');
        $pagination->headers->set('User-Agent', 'Mozilla/5.0');
        $robot = Request::create('/products/search?keyword=case', 'GET');
        $robot->headers->set('User-Agent', 'Googlebot/2.1');

        $this->assertFalse($service->record($pagination));
        $this->assertFalse($service->record($robot));
    }

    public function test_middleware_tracks_successful_search_response_once(): void
    {
        $service = new class extends SearchKeywordService
        {
            public int $records = 0;

            public function record(Request $request): bool
            {
                $this->records++;

                return true;
            }
        };
        $request = Request::create('/products/search?keyword=case', 'GET');
        $route   = (new Route('GET', 'products/search', fn () => null))->name('shop.products.search');
        $request->setRouteResolver(static fn (): Route => $route);

        $response = (new TrackSearchKeyword($service))->handle($request, static fn () => response('ok'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $service->records);
    }

    public function test_middleware_does_not_track_failed_search_response(): void
    {
        $service = new class extends SearchKeywordService
        {
            public int $records = 0;

            public function record(Request $request): bool
            {
                $this->records++;

                return true;
            }
        };
        $request = Request::create('/products/search?keyword=case', 'GET');
        $route   = (new Route('GET', 'products/search', fn () => null))->name('shop.products.search');
        $request->setRouteResolver(static fn (): Route => $route);

        (new TrackSearchKeyword($service))->handle($request, static fn () => response('failed', 500));

        $this->assertSame(0, $service->records);
    }
}
