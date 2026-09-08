<?php

namespace Plugin\Meilisearch;

use Beike\Models\Product;
use Beike\Models\ProductCategory;
use Beike\Models\ProductDescription;
use Beike\Models\ProductSku;
use Beike\Models\ProductView;
use Plugin\Meilisearch\Jobs\DeleteProduct;
use Plugin\Meilisearch\Jobs\SyncProduct;
use Plugin\Meilisearch\Observers\ProductObserver;
use Plugin\Meilisearch\Observers\RelatedProductObserver;
use Plugin\Meilisearch\Services\CatalogContext;
use Plugin\Meilisearch\Services\IndexTaskService;

class Bootstrap
{
    public function boot(): void
    {
        app()->singleton(IndexTaskService::class);

        Product::observe(ProductObserver::class);
        ProductSku::observe(RelatedProductObserver::class);
        ProductDescription::observe(RelatedProductObserver::class);
        ProductCategory::observe(RelatedProductObserver::class);
        ProductView::observe(RelatedProductObserver::class);

        add_hook_action('admin.product.destroy_by_ids.after', function ($productIds) {
            foreach ((array) $productIds as $productId) {
                DeleteProduct::dispatch((int) $productId, CatalogContext::current());
            }
        });

        add_hook_action('admin.product.restore.after', function ($productId) {
            SyncProduct::dispatch((int) $productId, CatalogContext::current());
        });

        add_hook_action('admin_api.product.destroy.after', function ($product) {
            DeleteProduct::dispatch((int) $product->id, CatalogContext::current());
        });

        // 索引管理面板挂在通用插件编辑页下方，设置字段和索引操作保持同一入口。
        add_hook_blade('admin.plugin.form.after', function ($callback, $content, array $data = []): string {
            $plugin = $data['plugin'] ?? null;
            if (! $plugin || $plugin->code !== 'meilisearch') {
                return $content;
            }

            // 追加而不是替换，避免覆盖其他插件挂在同一钩子上的内容。
            return $content . view('Meilisearch::admin.index_panel')->render();
        });
    }
}
