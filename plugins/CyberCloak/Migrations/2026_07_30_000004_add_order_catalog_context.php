<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 为订单商品保存商品库来源、映射版本和履约身份，保证历史订单不依赖当前请求上下文。
     */
    public function up(): void
    {
        if (Schema::hasTable('order_products')) {
            Schema::table('order_products', function (Blueprint $table): void {
                if (! Schema::hasColumn('order_products', 'catalog_mode')) {
                    $table->string('catalog_mode', 8)->default('real')->after('order_number')->index();
                }
                if (! Schema::hasColumn('order_products', 'catalog_product_id')) {
                    $table->unsignedBigInteger('catalog_product_id')->nullable()->after('catalog_mode')->index();
                }
                if (! Schema::hasColumn('order_products', 'catalog_sku_id')) {
                    $table->unsignedBigInteger('catalog_sku_id')->nullable()->after('catalog_product_id')->index();
                }
                if (! Schema::hasColumn('order_products', 'fulfillment_sku')) {
                    $table->string('fulfillment_sku', 128)->nullable()->after('product_sku')->index();
                }
                if (! Schema::hasColumn('order_products', 'catalog_mapping_version')) {
                    $table->string('catalog_mapping_version', 64)->nullable()->after('fulfillment_sku')->index();
                }
            });

            // 旧订单只能确认来自主库，履约 SKU 使用原订单 SKU，避免升级后库存恢复失去目标。
            DB::table('order_products')->whereNull('catalog_mode')->update(['catalog_mode' => 'real']);
            DB::table('order_products')->whereNull('catalog_product_id')->update([
                'catalog_product_id' => DB::raw('product_id'),
            ]);
            DB::table('order_products')->whereNull('fulfillment_sku')->update([
                'fulfillment_sku' => DB::raw('product_sku'),
            ]);
        }

        // 展示库商品表在阶段二没有销售字段，阶段五补齐后可记录展示商品销量。
        $catalog = Schema::connection('catalog_public');
        if ($catalog->hasTable('products') && ! $catalog->hasColumn('products', 'sales')) {
            $catalog->table('products', function (Blueprint $table): void {
                $table->unsignedInteger('sales')->default(0)->after('position');
            });
        }
    }

    /**
     * 删除阶段五新增字段，保留原有订单商品数据和展示库商品表。
     */
    public function down(): void
    {
        if (Schema::hasTable('order_products')) {
            Schema::table('order_products', function (Blueprint $table): void {
                foreach (['catalog_mode', 'catalog_product_id', 'catalog_sku_id', 'fulfillment_sku', 'catalog_mapping_version'] as $column) {
                    if (Schema::hasColumn('order_products', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        $catalog = Schema::connection('catalog_public');
        if ($catalog->hasColumn('products', 'sales')) {
            $catalog->table('products', function (Blueprint $table): void {
                $table->dropColumn('sales');
            });
        }
    }
};
