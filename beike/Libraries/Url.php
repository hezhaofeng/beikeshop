<?php

/**
 * Url.php
 *
 * @copyright  2023 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2023-03-15 14:13:39
 * @modified   2023-03-15 14:13:39
 */

namespace Beike\Libraries;

use Beike\Repositories\BrandRepo;
use Beike\Repositories\CategoryRepo;
use Beike\Repositories\PageCategoryRepo;
use Beike\Repositories\PageRepo;
use Beike\Repositories\ProductRepo;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Plugin\CyberCloak\Services\CatalogRouteService;
use Plugin\CyberCloak\Services\StoreContext;

class Url
{
    public const TYPES = [
        'category', 'product', 'brand', 'page', 'page_category', 'order', 'rma', 'static', 'custom',
    ];

    public static function getInstance(): self
    {
        return new self;
    }

    /**
     * Handle link.
     *
     * @return mixed|string
     * @throws \Exception
     */
    public function link($type, $value)
    {
        // 访问模式插件可在默认模型查询前直接提供商品/分类 URL。
        $mapped = hook_filter('url.link', ['type' => $type, 'value' => $value, 'url' => '', 'handled' => false]);
        if (($mapped['handled'] ?? false) === true) {
            return (string) ($mapped['url'] ?? '');
        }

        if (empty($type) || empty($value) || ! in_array($type, self::TYPES)) {
            $result = hook_filter('url.link', ['type' => $type, 'value' => $value, 'url' => '']);
            if (! empty($result['url'])) {
                return $result['url'];
            }

            return '';
        }

        if (is_array($value)) {
            throw new \Exception('Value must be integer, string or object');
        }

        if ($type == 'category') {
            if (! $value instanceof \Beike\Models\Category) {
                if ($this->isPublicCatalog()) {
                    $urlId = app(CatalogRouteService::class)->urlIdForRealRecord('category', (int) $value);

                    return $urlId > 0 ? shop_route('categories.show', ['category' => $urlId]) : '';
                }

                $value = \Beike\Models\Category::query()->find($value);
            }

            return $value->url ?? '';
        } elseif ($type == 'product') {
            if (! $value instanceof \Beike\Models\Product) {
                if ($this->isPublicCatalog()) {
                    $urlId = app(CatalogRouteService::class)->urlIdForRealRecord('product', (int) $value);

                    return $urlId > 0 ? shop_route('products.show', ['product' => $urlId]) : '';
                }

                $value = \Beike\Models\Product::query()->find($value);
            }

            return $value->url ?? '';
        } elseif ($type == 'brand') {
            if (! $value instanceof \Beike\Models\Brand) {
                $value = \Beike\Models\Brand::query()->find($value);
            }

            return $value->url ?? '';
        } elseif ($type == 'page') {
            if (! $value instanceof \Beike\Models\Page) {
                $value = \Beike\Models\Page::query()->where('active', 1)->find($value);
            }

            return $value->url ?? '';
        } elseif ($type == 'page_category') {
            if (! $value instanceof \Beike\Models\PageCategory) {
                $value = \Beike\Models\PageCategory::query()->find($value);
            }

            return $value->url ?? '';
        } elseif ($type == 'order') {
            return shop_route('account.order.show', ['number' => $value]);
        } elseif ($type == 'rma') {
            return shop_route('account.rma.show', ['id' => $value]);
        } elseif ($type == 'static') {
            if (! Route::has('shop.' . $value)) {
                return '';
            }

            return shop_route($value);
        } elseif ($type == 'custom') {
            if (Str::startsWith($value, ['http://', 'https://'])) {
                return $value;
            }

            return "//{$value}";
        }

        return '';
    }

    /**
     * Handle link label
     *
     * @param $type
     * @param $value
     * @param $texts
     * @return mixed
     */
    public function label($type, $value, $texts)
    {
        $types = ['category', 'product', 'brand', 'page', 'page_category', 'static', 'custom'];
        if (empty($type) || empty($value) || ! in_array($type, $types)) {
            $result = hook_filter('url.label', ['type' => $type, 'value' => $value, 'texts' => $texts, 'label' => '']);
            if (! empty($result['label'])) {
                return $result['label'];
            }

            return '';
        }

        $locale = locale();
        $text   = $texts[$locale] ?? '';
        if ($text) {
            return $text;
        }

        if ($type == 'category') {
            if ($this->isPublicCatalog() && ! is_object($value)) {
                $publicId = app(CatalogRouteService::class)->publicRecordIdForRealRecord('category', (int) $value);

                return $publicId > 0 ? CategoryRepo::getName($publicId) : '';
            }

            return CategoryRepo::getName($value);
        } elseif ($type == 'product') {
            if ($this->isPublicCatalog() && ! is_object($value)) {
                $publicId = app(CatalogRouteService::class)->publicRecordIdForRealRecord('product', (int) $value);

                return $publicId > 0 ? ProductRepo::getName($publicId) : '';
            }

            return ProductRepo::getName($value);
        } elseif ($type == 'brand') {
            return BrandRepo::getName($value);
        } elseif ($type == 'page') {
            return PageRepo::getName($value);
        } elseif ($type == 'page_category') {
            return PageCategoryRepo::getName($value);
        } elseif ($type == 'static') {
            $value = $this->handleLocale($value);

            return trans('shop/' . $value);
        } elseif ($type == 'custom') {
            return $text;
        }

        return '';
    }

    /**
     * 判断当前是否处于 public 商品库前台请求。
     */
    private function isPublicCatalog(): bool
    {
        if (! app()->bound(StoreContext::class)) {
            return false;
        }

        $context = app(StoreContext::class);

        return $context->isActive() && $context->isPublic();
    }

    /**
     * preg_replace('/\/([^\/]+)$/', '.$1', str_replace(".", "/", $value));
     *
     * @param $value
     * @return string
     */
    private function handleLocale($value): string
    {
        $parts = explode('.', $value);
        if (count($parts) < 2) {
            return $value;
        }

        $result = '';
        foreach ($parts as $index => $part) {
            if ($index < count($parts) - 2) {
                $result .= $part . '/';
            } elseif ($index < count($parts) - 1) {
                $result .= $part . '.';
            } else {
                $result .= $part;
            }
        }

        return $result;
    }
}
