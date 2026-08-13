<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 清理旧版 CyberCloak 写入订单、购物车和收藏表的商品库字段，恢复 BeikeShop 原有数据结构。
     */
    public function up(): void
    {
        $this->restoreHistoricalOrderProducts();
        $this->removePublicCartAndWishlistItems();

        $this->dropColumns('cart_products', [
            'catalog_mode',
            'catalog_product_id',
            'catalog_sku_id',
            'fulfillment_sku',
        ]);
        $this->dropColumns('customer_wishlists', [
            'catalog_mode',
            'catalog_product_id',
        ]);
        $this->dropColumns('order_products', [
            'catalog_mode',
            'catalog_product_id',
            'catalog_sku_id',
            'fulfillment_sku',
            'catalog_mapping_version',
        ]);
        $this->dropColumns('orders', [
            'catalog_review_status',
            'catalog_reviewed_by',
            'catalog_reviewed_at',
            'catalog_review_note',
        ]);
        $this->dropColumns('catalog_sku_mappings', ['fulfillment_sku']);
    }

    /**
     * 将旧展示订单中可识别的履约 SKU 回填为真实商品身份，保证历史订单回到 BeikeShop 原生关联。
     */
    private function restoreHistoricalOrderProducts(): void
    {
        if (! Schema::hasTable('order_products')
            || ! Schema::hasColumn('order_products', 'catalog_mode')
            || ! Schema::hasColumn('order_products', 'fulfillment_sku')) {
            return;
        }

        DB::table('order_products')
            ->where('catalog_mode', 'public')
            ->whereNotNull('fulfillment_sku')
            ->where('fulfillment_sku', '<>', '')
            ->orderBy('id')
            ->chunkById(200, function ($orderProducts): void {
                foreach ($orderProducts as $orderProduct) {
                    $realSku = DB::table('product_skus')
                        ->where('sku', $orderProduct->fulfillment_sku)
                        ->orderBy('id')
                        ->first(['product_id', 'sku']);
                    if (! $realSku) {
                        continue;
                    }

                    DB::table('order_products')->where('id', $orderProduct->id)->update([
                        'product_id'  => $realSku->product_id,
                        'product_sku' => $realSku->sku,
                    ]);
                }
            });
    }

    /**
     * 展示商品 ID 不属于主库，删除这些临时购物车和收藏明细，避免回退后错误关联到同 ID 的真实商品。
     */
    private function removePublicCartAndWishlistItems(): void
    {
        foreach (['cart_products', 'customer_wishlists'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'catalog_mode')) {
                DB::table($tableName)->where('catalog_mode', 'public')->delete();
            }
        }
    }

    /**
     * 此迁移用于永久移除废弃字段；回滚不重建订单和购物车映射结构。
     */
    public function down(): void
    {
        // 保持空实现，避免回滚时再次引入已废弃的订单和购物车映射。
    }

    /**
     * 按字段逐个删除，兼容不同历史安装版本和部分迁移已执行的数据库。
     *
     * @param array<int, string> $columns
     */
    private function dropColumns(string $tableName, array $columns): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns): void {
            foreach ($columns as $column) {
                if (Schema::hasColumn($tableName, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
