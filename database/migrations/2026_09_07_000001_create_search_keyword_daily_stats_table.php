<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 按天聚合搜索次数，既支持近期热度排序，也避免保存逐次搜索明细和访客信息。
     */
    public function up(): void
    {
        $schema = Schema::connection((string) config('database.default', 'mysql'));

        $schema->create('search_keyword_daily_stats', function (Blueprint $table): void {
            $table->id();
            $table->string('catalog', 20)->default('real')->comment('商品库：real/public');
            $table->string('locale', 20)->comment('搜索语言');
            $table->string('keyword', 100)->comment('用于前台展示的关键词');
            $table->string('normalized_keyword', 100)->comment('用于聚合的标准化关键词');
            $table->date('search_date')->comment('统计日期');
            $table->unsignedBigInteger('search_count')->default(0)->comment('当日有效搜索次数');
            $table->timestamp('last_searched_at')->nullable()->comment('最后搜索时间');
            $table->timestamps();

            $table->unique(
                ['catalog', 'locale', 'normalized_keyword', 'search_date'],
                'search_keyword_daily_stats_unique'
            );
            $table->index(
                ['catalog', 'locale', 'search_date', 'search_count'],
                'search_keyword_daily_stats_ranking'
            );
        });
    }

    public function down(): void
    {
        Schema::connection((string) config('database.default', 'mysql'))
            ->dropIfExists('search_keyword_daily_stats');
    }
};
