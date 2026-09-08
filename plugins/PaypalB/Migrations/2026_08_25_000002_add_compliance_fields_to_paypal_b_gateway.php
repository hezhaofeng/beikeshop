<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paypal_b_accounts', function (Blueprint $table): void {
            $table->boolean('wallet_enabled')->default(true)->after('supported_currencies');
            $table->boolean('card_enabled')->default(false)->after('wallet_enabled');
        });

        Schema::table('paypal_b_transactions', function (Blueprint $table): void {
            $table->string('order_snapshot_hash', 64)->nullable()->after('provider_status');
            $table->longText('buyer_snapshot')->nullable()->after('order_snapshot_hash');
            $table->json('order_items')->nullable()->after('buyer_snapshot');
            $table->json('order_totals')->nullable()->after('order_items');
            $table->longText('shipping_address')->nullable()->after('order_totals');
            $table->longText('fulfillment_evidence')->nullable()->after('shipping_address');
            $table->timestamp('fulfillment_updated_at')->nullable()->after('fulfillment_evidence');
            $table->boolean('failure_retryable')->nullable()->after('fulfillment_updated_at');
            $table->string('failure_class', 32)->nullable()->after('failure_retryable');
        });
    }

    public function down(): void
    {
        Schema::table('paypal_b_transactions', function (Blueprint $table): void {
            $table->dropColumn([
                'order_snapshot_hash',
                'buyer_snapshot',
                'order_items',
                'order_totals',
                'shipping_address',
                'fulfillment_evidence',
                'fulfillment_updated_at',
                'failure_retryable',
                'failure_class',
            ]);
        });

        Schema::table('paypal_b_accounts', function (Blueprint $table): void {
            $table->dropColumn(['wallet_enabled', 'card_enabled']);
        });
    }
};
