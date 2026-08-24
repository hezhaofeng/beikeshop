<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 为订单增加广告归因字段，并建立服务端事件幂等日志。
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('ad_tracking_source')->nullable()->index('orders_ad_tracking_source');
            $table->string('ad_tracking_medium')->nullable();
            $table->string('ad_tracking_campaign')->nullable()->index('orders_ad_tracking_campaign');
            $table->string('ad_tracking_content')->nullable();
            $table->string('ad_tracking_term')->nullable();
            $table->string('ad_tracking_click_id')->nullable();
            $table->text('ad_tracking_landing_url')->nullable();
            $table->text('ad_tracking_referrer')->nullable();
            $table->json('ad_tracking_data')->nullable();
        });

        Schema::create('ad_tracking_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable()->index('ad_tracking_events_order_id');
            $table->string('event_name', 80);
            $table->string('platform', 50);
            $table->string('event_id', 191)->unique('ad_tracking_events_event_id');
            $table->string('status', 30)->default('sending')->index('ad_tracking_events_status');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('request')->nullable();
            $table->longText('response')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['platform', 'event_name'], 'ad_tracking_events_platform_event');
        });
    }

    /**
     * 卸载插件时删除插件自身的字段和事件日志。
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_tracking_events');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_ad_tracking_source');
            $table->dropIndex('orders_ad_tracking_campaign');
            $table->dropColumn([
                'ad_tracking_source',
                'ad_tracking_medium',
                'ad_tracking_campaign',
                'ad_tracking_content',
                'ad_tracking_term',
                'ad_tracking_click_id',
                'ad_tracking_landing_url',
                'ad_tracking_referrer',
                'ad_tracking_data',
            ]);
        });
    }
};
