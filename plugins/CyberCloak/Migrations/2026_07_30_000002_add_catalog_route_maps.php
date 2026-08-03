<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 为数字商品/分类 URL 建立主库稳定路由映射，并补齐展示库模型需要的字段。
     */
    public function up(): void
    {
        $catalog = Schema::connection('catalog_public');

        if ($catalog->hasColumn('categories', 'image') === false) {
            $catalog->table('categories', function (Blueprint $table): void {
                $table->string('image')->default('');
            });
        }

        if ($catalog->hasColumn('brands', 'active') === false) {
            $catalog->table('brands', function (Blueprint $table): void {
                $table->boolean('active')->default(true)->index();
            });
            if ($catalog->hasColumn('brands', 'status')) {
                DB::connection('catalog_public')->table('brands')->update(['active' => DB::raw('status')]);
            }
        }

        if ($catalog->hasColumn('products', 'shipping') === false) {
            $catalog->table('products', function (Blueprint $table): void {
                $table->boolean('shipping')->default(true)->after('price');
            });
        }

        // 阶段二迁移使用过单数列名，升级时复制到核心模型统一使用的字段。
        foreach (['category_descriptions', 'product_descriptions'] as $table) {
            if ($catalog->hasColumn($table, 'meta_keywords') === false && $catalog->hasColumn($table, 'meta_keyword')) {
                $catalog->table($table, function (Blueprint $blueprint): void {
                    $blueprint->string('meta_keywords')->default('');
                });
                DB::connection('catalog_public')->table($table)->update(['meta_keywords' => DB::raw('meta_keyword')]);
            }
        }

        if (! Schema::hasTable('catalog_route_maps')) {
            Schema::create('catalog_route_maps', function (Blueprint $table): void {
                $table->id();
                $table->string('route_type', 16);
                $table->unsignedBigInteger('url_id');
                $table->unsignedBigInteger('real_record_id');
                $table->unsignedBigInteger('public_record_id')->nullable();
                $table->string('status', 16)->default('active')->index();
                $table->string('mapping_version', 64)->nullable()->index();
                $table->timestamps();
                $table->unique(['route_type', 'url_id']);
                $table->index(['route_type', 'real_record_id']);
                $table->index(['route_type', 'public_record_id']);
            });
        }
    }

    /**
     * 删除阶段三新增的稳定路由映射和兼容字段。
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_route_maps');
        $catalog = Schema::connection('catalog_public');
        if ($catalog->hasColumn('categories', 'image')) {
            $catalog->table('categories', function (Blueprint $table): void {
                $table->dropColumn('image');
            });
        }
        if ($catalog->hasColumn('brands', 'active')) {
            $catalog->table('brands', function (Blueprint $table): void {
                $table->dropColumn('active');
            });
        }
        foreach (['category_descriptions', 'product_descriptions'] as $table) {
            if ($catalog->hasColumn($table, 'meta_keywords')) {
                $catalog->table($table, function (Blueprint $blueprint): void {
                    $blueprint->dropColumn('meta_keywords');
                });
            }
        }
    }
};
