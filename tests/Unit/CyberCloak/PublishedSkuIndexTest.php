<?php

namespace Tests\Unit\CyberCloak;

use Plugin\CyberCloak\Services\SkuMappingService;
use Tests\TestCase;

class PublishedSkuIndexTest extends TestCase
{
    /**
     * 前台 SKU 过滤应使用展示库本地索引，而不是把全部已映射 SKU ID 拼成 WHERE IN。
     */
    public function test_published_sku_constraint_uses_local_exists_index(): void
    {
        $subQuery = new class
        {
            public array $calls = [];

            public function selectRaw(string $expression): self
            {
                $this->calls[] = ['selectRaw', $expression];

                return $this;
            }

            public function from(string $table): self
            {
                $this->calls[] = ['from', $table];

                return $this;
            }

            public function whereColumn(string $first, string $second): self
            {
                $this->calls[] = ['whereColumn', $first, $second];

                return $this;
            }

            public function where(string $column, int $value): self
            {
                $this->calls[] = ['where', $column, $value];

                return $this;
            }
        };
        $query = new class($subQuery)
        {
            public function __construct(private readonly object $subQuery)
            {
            }

            public function whereExists(callable $callback): void
            {
                $callback($this->subQuery);
            }
        };

        (new SkuMappingService)->constrainToPublishedPublicSkus($query);

        $this->assertSame([
            ['selectRaw', '1'],
            ['from', 'catalog_published_sku_mappings as published_skus'],
            ['whereColumn', 'published_skus.public_sku_id', 'product_skus.id'],
            ['where', 'published_skus.active', 1],
        ], $subQuery->calls);
    }

    /**
     * 发布索引迁移必须在展示库建立唯一 SKU 键，供前台 EXISTS 查询走索引。
     */
    public function test_published_sku_index_migration_has_required_keys(): void
    {
        $migration = file_get_contents(base_path('plugins/CyberCloak/Migrations/2026_08_07_000011_create_catalog_published_sku_mappings.php'));

        $this->assertStringContainsString("Schema::connection('catalog_public')", $migration);
        $this->assertStringContainsString("unsignedBigInteger('public_sku_id')->unique()", $migration);
        $this->assertStringContainsString("index(['active', 'public_sku_id'])", $migration);
    }

    /**
     * 首页和列表查询不应再保留全量 SKU ID 的内存读取路径。
     */
    public function test_product_repository_uses_published_sku_index_constraint(): void
    {
        $productRepository = file_get_contents(base_path('beike/Repositories/ProductRepo.php'));
        $brandController   = file_get_contents(base_path('beike/Shop/Http/Controllers/BrandController.php'));

        $this->assertStringNotContainsString('sellablePublicSkuIds()', $productRepository);
        $this->assertStringContainsString('constrainToPublishedPublicSkus', $productRepository);
        $this->assertStringContainsString('constrainToPublishedPublicSkus', $brandController);
    }

    /**
     * 首次发布的主库事务失败时，展示库不能保留未发布版本的临时索引。
     */
    public function test_first_publish_failure_clears_temporary_public_index(): void
    {
        $service = file_get_contents(base_path('plugins/CyberCloak/Services/SkuMappingService.php'));

        $this->assertStringContainsString('} else {', $service);
        $this->assertStringContainsString('$this->clearPublishedSkuIndex();', $service);
        $this->assertStringContainsString('private function clearPublishedSkuIndex(): void', $service);
    }

    /**
     * 展示库不含客户收藏表，商品浏览不应预加载或懒加载该关系。
     */
    public function test_public_catalog_does_not_query_customer_wishlists(): void
    {
        $productRepository = file_get_contents(base_path('beike/Repositories/ProductRepo.php'));
        $brandController   = file_get_contents(base_path('beike/Shop/Http/Controllers/BrandController.php'));
        $simpleResource    = file_get_contents(base_path('beike/Shop/Http/Resources/ProductSimple.php'));
        $detailResource    = file_get_contents(base_path('beike/Shop/Http/Resources/ProductDetail.php'));

        $this->assertStringContainsString('if (! self::isPublicCatalog())', $productRepository);
        $this->assertStringContainsString('if (! ($context->isActive() && $context->isPublic()))', $brandController);
        $this->assertStringContainsString("relationLoaded('inCurrentWishlist')", $simpleResource);
        $this->assertStringContainsString("relationLoaded('inCurrentWishlist')", $detailResource);
    }
}
