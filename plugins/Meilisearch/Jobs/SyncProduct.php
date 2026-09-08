<?php

namespace Plugin\Meilisearch\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Plugin\Meilisearch\Services\CatalogContext;
use Plugin\Meilisearch\Services\ProductIndexer;

class SyncProduct implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $afterCommit = true;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public int $productId, public string $catalog = CatalogContext::REAL)
    {
        $this->onQueue('meilisearch');
    }

    /**
     * 真实库和展示库的商品 ID 空间互不相干，去重键必须带上商品库。
     */
    public function uniqueId(): string
    {
        return $this->catalog . ':' . $this->productId;
    }

    public function handle(ProductIndexer $indexer): void
    {
        $indexer->syncProduct($this->productId, $this->catalog);
    }
}
