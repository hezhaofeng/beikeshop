<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 为购物车和收藏记录保存商品库来源，避免用户切换访问模式后串库。
     */
    public function up(): void
    {
        if (Schema::hasTable('cart_products')) {
            Schema::table('cart_products', function (Blueprint $table): void {
                if (! Schema::hasColumn('cart_products', 'catalog_mode')) {
                    $table->string('catalog_mode', 8)->default('real')->after('session_id')->index();
                }
                if (! Schema::hasColumn('cart_products', 'catalog_product_id')) {
                    $table->unsignedBigInteger('catalog_product_id')->nullable()->after('product_id')->index();
                }
                if (! Schema::hasColumn('cart_products', 'catalog_sku_id')) {
                    $table->unsignedBigInteger('catalog_sku_id')->nullable()->after('catalog_product_id')->index();
                }
                if (! Schema::hasColumn('cart_products', 'fulfillment_sku')) {
                    $table->string('fulfillment_sku', 128)->nullable()->after('product_sku');
                }
            });

            DB::table('cart_products')->whereNull('catalog_product_id')->update([
                'catalog_product_id' => DB::raw('product_id'),
            ]);
            DB::table('cart_products')->whereNull('catalog_mode')->update(['catalog_mode' => 'real']);
        }

        if (Schema::hasTable('customer_wishlists')) {
            Schema::table('customer_wishlists', function (Blueprint $table): void {
                if (! Schema::hasColumn('customer_wishlists', 'catalog_mode')) {
                    $table->string('catalog_mode', 8)->default('real')->after('product_id')->index();
                }
                if (! Schema::hasColumn('customer_wishlists', 'catalog_product_id')) {
                    $table->unsignedBigInteger('catalog_product_id')->nullable()->after('catalog_mode')->index();
                }
            });

            DB::table('customer_wishlists')->whereNull('catalog_product_id')->update([
                'catalog_product_id' => DB::raw('product_id'),
            ]);
            DB::table('customer_wishlists')->whereNull('catalog_mode')->update(['catalog_mode' => 'real']);
        }
    }

    /**
     * 删除阶段四新增的商品库来源字段，保留原有购物车和收藏数据。
     */
    public function down(): void
    {
        if (Schema::hasTable('cart_products')) {
            Schema::table('cart_products', function (Blueprint $table): void {
                foreach (['catalog_mode', 'catalog_product_id', 'catalog_sku_id', 'fulfillment_sku'] as $column) {
                    if (Schema::hasColumn('cart_products', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('customer_wishlists')) {
            Schema::table('customer_wishlists', function (Blueprint $table): void {
                foreach (['catalog_mode', 'catalog_product_id'] as $column) {
                    if (Schema::hasColumn('customer_wishlists', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
