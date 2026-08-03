<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建供应商 IP 本地缓存、同步日志，并为展示订单增加人工审核字段。
     */
    public function up(): void
    {
        if (! Schema::hasTable('cyber_cloak_ip_provider_entries')) {
            Schema::create('cyber_cloak_ip_provider_entries', function (Blueprint $table): void {
                $table->id();
                $table->string('provider_code', 64);
                $table->string('ip', 128);
                $table->string('ip_version', 8);
                $table->string('source', 128)->default('provider');
                $table->boolean('active')->default(true)->index();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();
                $table->unique(['provider_code', 'ip']);
            });
        }

        if (! Schema::hasTable('cyber_cloak_ip_provider_sync_logs')) {
            Schema::create('cyber_cloak_ip_provider_sync_logs', function (Blueprint $table): void {
                $table->id();
                $table->string('provider_code', 64);
                $table->string('status', 16)->index();
                $table->unsignedInteger('fetched_count')->default(0);
                $table->unsignedInteger('accepted_count')->default(0);
                $table->text('error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->index(['provider_code', 'created_at']);
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table): void {
                if (! Schema::hasColumn('orders', 'catalog_review_status')) {
                    $table->string('catalog_review_status', 16)->default('not_required')->after('status')->index();
                }
                if (! Schema::hasColumn('orders', 'catalog_reviewed_by')) {
                    $table->unsignedBigInteger('catalog_reviewed_by')->nullable()->after('catalog_review_status');
                }
                if (! Schema::hasColumn('orders', 'catalog_reviewed_at')) {
                    $table->timestamp('catalog_reviewed_at')->nullable()->after('catalog_reviewed_by');
                }
                if (! Schema::hasColumn('orders', 'catalog_review_note')) {
                    $table->text('catalog_review_note')->nullable()->after('catalog_reviewed_at');
                }
            });
        }
    }

    /**
     * 回滚阶段六新增表和订单审核字段，不影响阶段一至阶段五的数据表。
     */
    public function down(): void
    {
        Schema::dropIfExists('cyber_cloak_ip_provider_sync_logs');
        Schema::dropIfExists('cyber_cloak_ip_provider_entries');

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table): void {
                foreach (['catalog_review_status', 'catalog_reviewed_by', 'catalog_reviewed_at', 'catalog_review_note'] as $column) {
                    if (Schema::hasColumn('orders', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
