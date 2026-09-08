<?php

namespace Plugin\Meilisearch\Observers;

use Illuminate\Database\Eloquent\Model;
use Plugin\Meilisearch\Jobs\SyncProduct;
use Plugin\Meilisearch\Services\CatalogContext;

class RelatedProductObserver
{
    public function saved(Model $model): void
    {
        $this->sync($model);
    }

    public function deleted(Model $model): void
    {
        $this->sync($model);
    }

    private function sync(Model $model): void
    {
        $productId = (int) $model->getAttribute('product_id');
        if ($productId > 0) {
            // 商品库由当前上下文决定，与模型实际写入的连接保持一致。
            SyncProduct::dispatch($productId, CatalogContext::current());
        }
    }
}
