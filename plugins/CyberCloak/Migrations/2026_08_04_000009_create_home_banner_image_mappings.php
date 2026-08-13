<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建首页 Banner 图片映射表；真实装修仍保存在 base.design_setting，主库只保存展示图片投影。
     */
    public function up(): void
    {
        if (Schema::hasTable('catalog_home_banner_mappings')) {
            return;
        }

        Schema::create('catalog_home_banner_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('module_id', 128)->index();
            $table->string('module_code', 128)->default('');
            $table->string('source_key', 64)->unique();
            $table->string('source_path', 255)->default('');
            $table->json('real_image');
            $table->json('public_image')->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->timestamps();
            $table->index(['module_id', 'status']);
        });
    }

    /**
     * 删除首页 Banner 图片映射表；不会删除 public/image/catalog 中的图片文件。
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_home_banner_mappings');
    }
};
