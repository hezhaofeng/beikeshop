<?php

namespace Plugin\CyberCloak\Services;

use Beike\Models\CartProduct;
use Beike\Models\Product;
use Beike\Models\ProductDescription;
use Beike\Models\ProductSku;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CatalogCartItemService
{
    /**
     * 获取当前前台请求的商品库模式；后台或迁移期间固定使用真实库。
     */
    public function currentMode(): string
    {
        $context = app(StoreContext::class);

        return $context->isActive() ? $context->mode() : StoreContext::REAL;
    }

    /**
     * 按指定商品库加载商品，并预加载当前语言描述和默认 SKU。
     */
    public function findProduct(string $mode, int $productId): ?Product
    {
        $connection = $this->connection($mode);
        $row        = DB::connection($connection)->table('products')
            ->where('id', $productId)
            ->where('active', 1)
            ->whereNull('deleted_at')
            ->first();
        if (! $row) {
            return null;
        }

        $product = (new Product)->newFromBuilder((array) $row, $connection);
        $this->setSourceUrlId($product, $mode);
        $description = DB::connection($connection)->table('product_descriptions')
            ->where('product_id', $productId)
            ->where('locale', locale())
            ->first()
            ?? DB::connection($connection)->table('product_descriptions')
                ->where('product_id', $productId)
                ->first();
        if ($description) {
            $product->setRelation('description', (new ProductDescription)->newFromBuilder((array) $description, $connection));
        }

        $masterSku = DB::connection($connection)->table('product_skus')
            ->where('product_id', $productId)
            ->where('active', 1)
            ->when($mode === StoreContext::PUBLIC, function ($query): void {
                $query->whereIn('id', $this->sellablePublicSkuIds());
            })
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
        if ($masterSku) {
            $sku = (new ProductSku)->newFromBuilder((array) $masterSku, $connection);
            $sku->setRelation('product', $product);
            $product->setRelation('masterSku', $sku);
        }
        if ($mode === StoreContext::PUBLIC && ! $masterSku) {
            return null;
        }

        return $product;
    }

    /**
     * 为跨模式购物车商品保存稳定的对外 URL 身份，避免 URL生成依赖当前请求模式。
     */
    private function setSourceUrlId(Product $product, string $mode): void
    {
        try {
            $column = $mode === StoreContext::PUBLIC ? 'public_record_id' : 'real_record_id';
            $main   = DB::connection((string) config('database.default', 'mysql'));
            $urlId  = $main
                ->table('catalog_route_maps')
                ->where('route_type', 'product')
                ->where('status', 'active')
                ->where($column, $product->getKey())
                ->orderBy('url_id')
                ->value('url_id');
            if ($urlId) {
                $product->setAttribute('source_url_id', (int) $urlId);

                return;
            }

            if ($mode === StoreContext::PUBLIC) {
                $version = $main->table('catalog_mapping_versions')
                    ->where('status', 'published')
                    ->orderByDesc('published_at')
                    ->value('version');
                $mappedUrlId = $version
                    ? $main->table('catalog_product_mappings')
                        ->where('mapping_version', $version)
                        ->where('public_product_id', $product->getKey())
                        ->where('status', 'confirmed')
                        ->value('real_product_id')
                    : null;
                if ($mappedUrlId) {
                    $product->setAttribute('source_url_id', (int) $mappedUrlId);

                    return;
                }
            }
        } catch (\Throwable) {
            // 路由映射迁移尚未执行时使用商品库内 ID兼容阶段性数据。
        }

        $hasRouteTable = false;
        try {
            $hasRouteTable = Schema::connection((string) config('database.default', 'mysql'))->hasTable('catalog_route_maps');
        } catch (\Throwable) {
            // 主库连接不可用时保留旧环境的同 ID 兼容行为。
        }
        $product->setAttribute('source_url_id', $hasRouteTable ? 0 : (int) $product->getKey());
    }

    /**
     * 按商品库和稳定 SKU 身份加载启用 SKU，防止关系查询回到当前请求的另一商品库。
     */
    public function findSku(string $mode, int $productId, ?int $skuId, string $skuCode = ''): ?ProductSku
    {
        $connection = $this->connection($mode);
        $query      = DB::connection($connection)->table('product_skus')
            ->where('product_id', $productId)
            ->where('active', 1);
        if ($skuId) {
            $query->where('id', $skuId);
        } else {
            $query->where('sku', $skuCode);
        }
        $row = $query->first();
        if (! $row) {
            return null;
        }
        if ($mode === StoreContext::PUBLIC && ! $this->isPublicSkuSellable((int) $row->id)) {
            return null;
        }

        return (new ProductSku)->newFromBuilder((array) $row, $connection);
    }

    /**
     * 按当前映射版本读取已确认的展示 SKU，供商品查询和加购共同过滤。
     *
     * @return array<int,int>
     */
    public function sellablePublicSkuIds(): array
    {
        try {
            $main    = DB::connection((string) config('database.default', 'mysql'));
            $version = $main->table('catalog_mapping_versions')
                ->where('status', 'published')
                ->orderByDesc('published_at')
                ->value('version');
            if (! $version) {
                return [];
            }

            return array_values(array_map('intval', $main->table('catalog_sku_mappings')
                ->where('mapping_version', $version)
                ->where('status', 'confirmed')
                ->whereNotNull('public_sku_id')
                ->orderBy('public_sku_id')
                ->pluck('public_sku_id')
                ->all()));
        } catch (\Throwable) {
            // 映射表尚未迁移或数据库暂不可用时，展示 SKU 默认不可售。
            return [];
        }
    }

    /**
     * 按当前商品库加载可售 SKU，供 CartRequest 和控制器使用同一套校验。
     */
    public function findSellableSkuById(string $mode, int $skuId): ?ProductSku
    {
        $connection = $this->connection($mode);
        $row        = DB::connection($connection)->table('product_skus as skus')
            ->join('products', 'products.id', '=', 'skus.product_id')
            ->where('skus.id', $skuId)
            ->where('skus.active', 1)
            ->where('products.active', 1)
            ->whereNull('products.deleted_at')
            ->select('skus.*')
            ->first();
        if (! $row || ($mode === StoreContext::PUBLIC && ! $this->isPublicSkuSellable($skuId))) {
            return null;
        }

        $sku     = (new ProductSku)->newFromBuilder((array) $row, $connection);
        $product = $this->findProduct($mode, (int) $row->product_id);
        if (! $product) {
            return null;
        }
        $sku->setRelation('product', $product);

        return $sku;
    }

    /**
     * 在服务层再次校验 SKU，避免绕过 CartRequest 直接调用加购服务。
     */
    public function isSellableSku(string $mode, ProductSku $sku): bool
    {
        if ($mode === StoreContext::REAL) {
            return (bool) $sku->active && (int) $sku->quantity >= 0;
        }

        return in_array((int) $sku->getKey(), $this->sellablePublicSkuIds(), true);
    }

    /**
     * 将购物车明细绑定到它自己保存的商品库商品和 SKU。
     */
    public function hydrate(CartProduct $cart): bool
    {
        $mode      = $this->normalizeMode($cart->catalog_mode ?: StoreContext::REAL);
        $productId = (int) ($cart->catalog_product_id ?: $cart->product_id);
        $skuId     = (int) ($cart->catalog_sku_id ?: 0);
        $product   = $this->findProduct($mode, $productId);
        $sku       = $this->findSku($mode, $productId, $skuId ?: null, (string) $cart->product_sku);
        if (! $product || ! $sku) {
            return false;
        }

        $sku->setRelation('product', $product);
        $cart->setAttribute('catalog_mode', $mode);
        $cart->setAttribute('catalog_product_id', $product->getKey());
        $cart->setAttribute('catalog_sku_id', $sku->getKey());
        $cart->setRelation('product', $product);
        $cart->setRelation('sku', $sku);

        $fulfillmentSku = $this->fulfillmentSku($mode, $sku);
        if ($fulfillmentSku === '') {
            return false;
        }
        // 每次 hydrate 都从当前 published 版本重算，避免旧购物车保留错误履约身份。
        $cart->setAttribute('fulfillment_sku', $fulfillmentSku);
        $cart->setAttribute('catalog_mapping_version', $this->mappingVersion($mode));

        return true;
    }

    /**
     * 获取当前已发布映射版本，供购物车转订单时写入不可变快照。
     */
    public function mappingVersion(string $mode): ?string
    {
        if ($mode !== StoreContext::PUBLIC) {
            return null;
        }

        try {
            $main = DB::connection((string) config('database.default', 'mysql'));

            return $main->table('catalog_mapping_versions')
                ->where('status', 'published')
                ->orderByDesc('published_at')
                ->value('version');
        } catch (\Throwable) {
            // 映射表尚未迁移时不伪造版本，展示商品也不会进入可履约订单。
            return null;
        }
    }

    /**
     * 获取当前映射版本中的履约 SKU；展示 SKU 未确认时返回空字符串并阻断交易。
     */
    public function fulfillmentSku(string $mode, ProductSku $sku): string
    {
        if ($mode === StoreContext::REAL) {
            return (string) $sku->sku;
        }

        try {
            $main    = DB::connection((string) config('database.default', 'mysql'));
            $version = $this->mappingVersion(StoreContext::PUBLIC);
            if ($version) {
                $mapped = $main->table('catalog_sku_mappings')
                    ->where('mapping_version', $version)
                    ->where('public_sku_id', $sku->getKey())
                    ->where('status', 'confirmed')
                    ->orderBy('real_sku_id')
                    ->orderBy('id')
                    ->value('fulfillment_sku');
                if ($mapped) {
                    return (string) $mapped;
                }
            }
        } catch (\Throwable) {
            // 映射表不可用时展示 SKU 不具备可验证的履约身份。
        }

        return '';
    }

    /**
     * 判断展示 SKU 是否存在当前已发布版本的 confirmed 映射。
     */
    private function isPublicSkuSellable(int $skuId): bool
    {
        return in_array($skuId, $this->sellablePublicSkuIds(), true);
    }

    /**
     * 把不受信任的商品库模式收敛为 real/public 两个合法值。
     */
    private function normalizeMode(string $mode): string
    {
        return in_array($mode, [StoreContext::REAL, StoreContext::PUBLIC], true) ? $mode : StoreContext::REAL;
    }

    /**
     * 根据运行配置返回商品库连接名。
     */
    private function connection(string $mode): string
    {
        $mode = $this->normalizeMode($mode);

        return (string) config("cyber_cloak.connections.{$mode}", $mode === StoreContext::PUBLIC ? 'catalog_public' : 'mysql');
    }
}
