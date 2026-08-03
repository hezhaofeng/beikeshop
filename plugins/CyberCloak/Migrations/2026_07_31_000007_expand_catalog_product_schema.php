<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 为已经执行旧版插件迁移的展示库补齐商品主表字段。
     *
     * 这些字段与主库 products 表保持一致，尤其是 weight 和 weight_class；
     * 爬虫仓储使用 products AS p 查询 p.weight，因此不能只在 product_skus 中保存重量。
     */
    public function up(): void
    {
        $catalog = Schema::connection('catalog_public');

        if (! $catalog->hasTable('products')) {
            return;
        }

        if (! $catalog->hasColumn('products', 'weight')) {
            $catalog->table('products', function (Blueprint $table): void {
                $table->double('weight', 8, 2)->default(0)->after('tax_class_id');
            });
        }

        if (! $catalog->hasColumn('products', 'weight_class')) {
            $catalog->table('products', function (Blueprint $table): void {
                $table->string('weight_class')->default('')->after('weight');
            });
        }

        // 旧版阶段五通常已经创建 sales，这里兼容只执行了部分迁移的展示库。
        if (! $catalog->hasColumn('products', 'sales')) {
            $catalog->table('products', function (Blueprint $table): void {
                $table->unsignedInteger('sales')->default(0)->after('position');
            });
        }
    }

    /**
     * 回滚本次补齐的重量字段；sales 可能由旧版阶段五创建，因此保留该列。
     */
    public function down(): void
    {
        $catalog = Schema::connection('catalog_public');
        if (! $catalog->hasTable('products')) {
            return;
        }

        $columns = array_values(array_filter(
            ['weight', 'weight_class'],
            static fn (string $column): bool => $catalog->hasColumn('products', $column)
        ));
        if ($columns === []) {
            return;
        }

        $catalog->table('products', function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }
};
