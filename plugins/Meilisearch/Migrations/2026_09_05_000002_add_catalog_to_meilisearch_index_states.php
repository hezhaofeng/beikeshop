<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CyberCloak 的真实库和展示库使用互不相干的商品 ID，索引必须按商品库隔离，
     * 活动索引记录也要从单一语言维度扩展为商品库加语言维度。
     */
    public function up(): void
    {
        if (! Schema::hasTable('meilisearch_index_states')) {
            return;
        }

        if (! Schema::hasColumn('meilisearch_index_states', 'catalog')) {
            Schema::table('meilisearch_index_states', function (Blueprint $table): void {
                // 存量记录都建立在真实库上，默认值保证升级后原有索引继续可用。
                $table->string('catalog', 16)->default('real')->after('id');
            });
        }

        Schema::table('meilisearch_index_states', function (Blueprint $table): void {
            try {
                $table->dropUnique('meilisearch_index_states_locale_unique');
            } catch (\Throwable) {
                // 索引可能已在早期版本中调整过，缺失时继续创建复合唯一键。
            }
        });

        Schema::table('meilisearch_index_states', function (Blueprint $table): void {
            $table->unique(['catalog', 'locale'], 'meilisearch_index_states_catalog_locale_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('meilisearch_index_states')) {
            return;
        }

        Schema::table('meilisearch_index_states', function (Blueprint $table): void {
            try {
                $table->dropUnique('meilisearch_index_states_catalog_locale_unique');
            } catch (\Throwable) {
                // 唯一键不存在时直接继续回滚字段。
            }
        });

        if (Schema::hasColumn('meilisearch_index_states', 'catalog')) {
            Schema::table('meilisearch_index_states', function (Blueprint $table): void {
                $table->dropColumn('catalog');
            });
        }

        Schema::table('meilisearch_index_states', function (Blueprint $table): void {
            $table->unique('locale', 'meilisearch_index_states_locale_unique');
        });
    }
};
