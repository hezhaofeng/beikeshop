<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 在展示库保存当前发布映射的本地索引，前台不再跨库读取完整 SKU ID 集合。
     */
    public function up(): void
    {
        $catalog = Schema::connection('catalog_public');
        if ($catalog->hasTable('catalog_published_sku_mappings')) {
            return;
        }

        $catalog->create('catalog_published_sku_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('public_sku_id')->unique();
            $table->unsignedBigInteger('public_product_id')->index();
            $table->string('mapping_version', 64)->index();
            $table->boolean('active')->default(false)->index();
            $table->timestamps();
            $table->index(['active', 'public_sku_id']);
        });
    }

    /**
     * 仅删除可由已发布映射重建的展示索引，不影响商品和主库映射记录。
     */
    public function down(): void
    {
        Schema::connection('catalog_public')->dropIfExists('catalog_published_sku_mappings');
    }
};
