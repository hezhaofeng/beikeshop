<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建风险 IP 档案和事件表；两表均位于真实主库，不写入展示商品库。
     */
    public function up(): void
    {
        if (! Schema::hasTable('cyber_cloak_traffic_risk_profiles')) {
            Schema::create('cyber_cloak_traffic_risk_profiles', function (Blueprint $table): void {
                $table->id();
                $table->string('ip_hash', 64)->unique();
                $table->text('encrypted_ip');
                $table->string('review_status', 16)->default('unreviewed')->index();
                $table->unsignedInteger('hit_count')->default(0);
                $table->unsignedTinyInteger('last_score')->default(0);
                $table->unsignedTinyInteger('max_score')->default(0);
                $table->string('last_action', 16)->default('observe');
                $table->json('last_reasons')->nullable();
                $table->json('last_signals')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_note')->nullable();
                $table->timestamp('first_seen_at')->nullable()->index();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->timestamps();
                $table->index(['review_status', 'last_seen_at']);
            });
        }

        if (! Schema::hasTable('cyber_cloak_traffic_risk_events')) {
            Schema::create('cyber_cloak_traffic_risk_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('profile_id')->index();
                $table->string('ip_hash', 64)->index();
                $table->unsignedTinyInteger('score');
                $table->string('action', 16)->index();
                $table->string('stage', 32);
                $table->json('reasons')->nullable();
                $table->json('signals')->nullable();
                $table->string('route', 255)->default('');
                $table->string('user_agent_hash', 64)->default('');
                $table->timestamp('occurred_at')->index();
                $table->timestamps();
                $table->index(['profile_id', 'occurred_at']);
            });
        }
    }

    /**
     * 回滚只删除本次新增的风险审计表，不影响映射、订单和购物车数据。
     */
    public function down(): void
    {
        Schema::dropIfExists('cyber_cloak_traffic_risk_events');
        Schema::dropIfExists('cyber_cloak_traffic_risk_profiles');
    }
};
