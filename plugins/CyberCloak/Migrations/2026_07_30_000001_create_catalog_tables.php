<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建展示库商品表和主库映射表。
     *
     * 展示库只保存商品浏览和映射所需的基础表，客户、购物车、订单等业务表仍位于主库。
     */
    public function up(): void
    {
        $catalog = Schema::connection('catalog_public');
        // 本地调试和旧版部署可能只完成了部分展示库建表，重复执行时保留已有数据。
        $createCatalogTable = static function (string $table, \Closure $definition) use ($catalog): void {
            if (! $catalog->hasTable($table)) {
                $catalog->create($table, $definition);
            }
        };
        $createMainTable = static function (string $table, \Closure $definition): void {
            if (! Schema::hasTable($table)) {
                Schema::create($table, $definition);
            }
        };

        $createCatalogTable('brands', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->char('first', 1)->default('');
            $table->string('logo')->default('');
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $createCatalogTable('categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('parent_id')->default(0)->index();
            $table->integer('position')->default(0);
            $table->string('image')->default('');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $createCatalogTable('category_paths', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('category_id')->index();
            $table->unsignedBigInteger('path_id')->index();
            $table->integer('level');
            $table->timestamps();
        });

        $createCatalogTable('category_descriptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('category_id')->index();
            $table->string('locale', 32);
            $table->string('name');
            $table->text('content')->nullable();
            $table->string('meta_title')->default('');
            $table->string('meta_description', 500)->default('');
            $table->string('meta_keywords')->default('');
            $table->timestamps();
            $table->unique(['category_id', 'locale']);
        });

        $createCatalogTable('products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('brand_id')->default(0)->index();
            $table->json('images')->nullable();
            $table->decimal('price', 15, 4)->default(0);
            $table->boolean('shipping')->default(true);
            $table->string('video')->default('');
            $table->integer('position')->default(0);
            $table->boolean('active')->default(true)->index();
            $table->json('variables')->nullable();
            $table->integer('tax_class_id')->default(0);
            // 商品主表的重量字段由主库 products.weight 直接同步，爬虫查询使用 p.weight。
            $table->double('weight', 8, 2)->default(0);
            $table->string('weight_class')->default('');
            $table->unsignedInteger('sales')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        $createCatalogTable('product_categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('category_id')->index();
            $table->timestamps();
            $table->unique(['product_id', 'category_id']);
        });

        $createCatalogTable('product_descriptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('locale', 32);
            $table->string('name');
            $table->text('content')->nullable();
            $table->string('meta_title')->default('');
            $table->string('meta_description', 500)->default('');
            $table->string('meta_keywords')->default('');
            $table->timestamps();
            $table->unique(['product_id', 'locale']);
        });

        $createCatalogTable('product_skus', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->json('variants')->nullable();
            $table->integer('position')->default(0);
            $table->json('images')->nullable();
            $table->string('model')->default('')->index();
            $table->string('sku')->default('')->unique();
            $table->double('price')->default(0);
            $table->double('origin_price')->default(0);
            $table->double('cost_price')->default(0);
            // SKU 重量沿用主库可空定义，空值表示源数据没有提供 SKU 重量。
            $table->double('weight', 8, 2)->nullable();
            $table->integer('quantity')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        $createMainTable('catalog_mapping_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('version', 64)->unique();
            $table->string('status', 16)->default('draft')->index();
            $table->string('source', 32)->default('exact');
            $table->unsignedInteger('product_count')->default(0);
            $table->unsignedInteger('sku_count')->default(0);
            $table->unsignedInteger('conflict_count')->default(0);
            $table->unsignedInteger('pending_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        $createMainTable('catalog_product_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('mapping_version', 64)->index();
            $table->unsignedBigInteger('real_product_id')->index();
            $table->unsignedBigInteger('public_product_id')->nullable()->index();
            $table->string('status', 16)->index();
            $table->string('match_type', 32)->default('none');
            $table->decimal('confidence', 5, 4)->default(0);
            $table->json('candidate_data')->nullable();
            $table->timestamps();
            $table->unique(['mapping_version', 'real_product_id']);
        });

        $createMainTable('catalog_sku_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('mapping_version', 64)->index();
            $table->unsignedBigInteger('real_sku_id')->index();
            $table->unsignedBigInteger('real_product_id')->index();
            $table->unsignedBigInteger('public_sku_id')->nullable()->index();
            $table->unsignedBigInteger('public_product_id')->nullable()->index();
            $table->string('real_sku', 128)->default('');
            $table->string('public_sku', 128)->default('');
            $table->string('fulfillment_sku', 128)->default('');
            $table->string('status', 16)->index();
            $table->string('match_type', 32)->default('none');
            $table->decimal('confidence', 5, 4)->default(0);
            $table->json('candidate_data')->nullable();
            $table->timestamps();
            $table->unique(['mapping_version', 'real_sku_id']);
        });
    }

    /**
     * 回滚阶段二新增表，映射表使用主库连接，展示表使用 catalog_public 连接。
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_sku_mappings');
        Schema::dropIfExists('catalog_product_mappings');
        Schema::dropIfExists('catalog_mapping_versions');

        $catalog = Schema::connection('catalog_public');
        $catalog->dropIfExists('product_skus');
        $catalog->dropIfExists('product_descriptions');
        $catalog->dropIfExists('product_categories');
        $catalog->dropIfExists('products');
        $catalog->dropIfExists('category_paths');
        $catalog->dropIfExists('category_descriptions');
        $catalog->dropIfExists('categories');
        $catalog->dropIfExists('brands');
    }
};
