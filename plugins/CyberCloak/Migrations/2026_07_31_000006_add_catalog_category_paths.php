<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 为已安装旧版本的展示库补建分类路径表；新安装由初始迁移直接创建。
     */
    public function up(): void
    {
        $catalog = Schema::connection('catalog_public');
        if (! $catalog->hasTable('category_paths')) {
            $catalog->create('category_paths', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('category_id')->index();
                $table->unsignedBigInteger('path_id')->index();
                $table->integer('level');
                $table->timestamps();
            });
        }
    }

    /**
     * 该表属于初始商品目录结构，升级迁移回滚时保留，避免误删初始迁移创建的表。
     */
    public function down(): void
    {
        // 分类路径表由初始迁移统一负责生命周期。
    }
};
