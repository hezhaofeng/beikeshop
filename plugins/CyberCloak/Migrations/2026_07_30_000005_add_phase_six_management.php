<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建供应商 IP 本地缓存和同步日志。
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
    }

    /**
     * 回滚阶段六新增的 IP 数据表。
     */
    public function down(): void
    {
        Schema::dropIfExists('cyber_cloak_ip_provider_sync_logs');
        Schema::dropIfExists('cyber_cloak_ip_provider_entries');
    }
};
