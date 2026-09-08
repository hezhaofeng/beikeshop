<?php

namespace Plugin\Meilisearch\Observers;

use Beike\Models\Product;
use Plugin\Meilisearch\Jobs\DeleteProduct;
use Plugin\Meilisearch\Jobs\SyncProduct;
use Plugin\Meilisearch\Services\CatalogContext;

class ProductObserver
{
    public function saved(Product $product): void
    {
        SyncProduct::dispatch((int) $product->id, CatalogContext::current());
    }

    public function deleted(Product $product): void
    {
        DeleteProduct::dispatch((int) $product->id, CatalogContext::current());
    }

    public function restored(Product $product): void
    {
        SyncProduct::dispatch((int) $product->id, CatalogContext::current());
    }

    public function forceDeleted(Product $product): void
    {
        DeleteProduct::dispatch((int) $product->id, CatalogContext::current());
    }
}
