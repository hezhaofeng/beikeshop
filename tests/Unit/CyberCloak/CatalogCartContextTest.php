<?php

namespace Tests\Unit\CyberCloak;

use Beike\Models\Category;
use Beike\Models\CustomerWishlist;
use Beike\Models\Product;
use Beike\Models\ProductSku;
use Beike\Models\ProductView;
use Plugin\CyberCloak\Services\CatalogCartItemService;
use Plugin\CyberCloak\Services\StoreContext;
use Tests\TestCase;

class CatalogCartContextTest extends TestCase
{
    /**
     * 购物车服务应根据当前前台上下文选择真实库或斗篷库。
     */
    public function test_current_mode_follows_store_context(): void
    {
        $context = new StoreContext;
        app()->instance(StoreContext::class, $context);
        $service = new CatalogCartItemService;

        $context->activate(['mode' => StoreContext::PUBLIC]);
        $this->assertSame(StoreContext::PUBLIC, $service->currentMode());

        $context->reset();
        $this->assertSame(StoreContext::REAL, $service->currentMode());
    }

    /**
     * 购物车跨模式读取时，显式连接必须覆盖当前请求的商品库上下文。
     */
    public function test_explicit_model_connection_wins_over_context(): void
    {
        $context = new StoreContext;
        app()->instance(StoreContext::class, $context);
        $context->activate(['mode' => StoreContext::PUBLIC]);

        $product = new Product;
        $product->setConnection('mysql');
        $sku = new ProductSku;
        $sku->setConnection('catalog_public');

        $this->assertSame('mysql', $product->getConnectionName());
        $this->assertSame('catalog_public', $sku->getConnectionName());
        $context->reset();
    }

    /**
     * 展示模式下收藏表仍使用主库，避免商品关系把它切到展示库。
     */
    public function test_customer_wishlist_stays_on_primary_connection_in_public_mode(): void
    {
        $context = new StoreContext;
        app()->instance(StoreContext::class, $context);
        $context->activate(['mode' => StoreContext::PUBLIC]);

        $wishlist = new CustomerWishlist;

        $this->assertSame((string) config('database.default', 'mysql'), $wishlist->getConnectionName());

        $context->reset();
    }

    /**
     * 展示模式下商品浏览记录关系仍使用主库，避免详情页访问量查询串到展示库。
     */
    public function test_product_views_stay_on_primary_connection_in_public_mode(): void
    {
        $context = new StoreContext;
        app()->instance(StoreContext::class, $context);
        $context->activate(['mode' => StoreContext::PUBLIC]);

        $product = new Product;

        $this->assertSame((string) config('database.default', 'mysql'), $product->views()->getRelated()->getConnectionName());
        $this->assertSame((string) config('database.default', 'mysql'), (new ProductView)->getConnectionName());

        $context->reset();
    }

    /**
     * 展示模式下分类路径使用展示库，避免面包屑读取主库分类路径。
     */
    public function test_category_paths_follow_catalog_connection_in_public_mode(): void
    {
        $context = new StoreContext;
        app()->instance(StoreContext::class, $context);
        $context->activate(['mode' => StoreContext::PUBLIC]);

        $category = new Category;
        $relation = $category->paths();

        $this->assertSame('catalog_public', $relation->getRelated()->getConnectionName());
        $this->assertSame('beike_cloak', $relation->getQuery()->getConnection()->getDatabaseName());
        $this->assertSame('category_paths', $relation->getQuery()->from);

        $context->reset();
    }

    /**
     * 真实模式没有映射表查询，履约 SKU应直接沿用真实 SKU。
     */
    public function test_real_mode_uses_real_sku_as_fulfillment_sku(): void
    {
        $service = new CatalogCartItemService;
        $sku     = new ProductSku(['sku' => 'REAL-SKU-001']);

        $this->assertSame('REAL-SKU-001', $service->fulfillmentSku(StoreContext::REAL, $sku));
    }
}
