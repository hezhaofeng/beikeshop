<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 保存不可逆的风险索引和 PayPal 风险信号审计，不把原始 IP 或客户端元数据用于查询。
     */
    public function up(): void
    {
        Schema::table('paypal_b_transactions', function (Blueprint $table): void {
            $table->string('paypal_item_source', 16)->default('a_order')->after('paypal_order_items');
            $table->string('buyer_email_hash', 64)->nullable()->after('paypal_item_source');
            $table->string('buyer_ip_hash', 64)->nullable()->after('buyer_email_hash');
            $table->string('risk_fingerprint', 64)->nullable()->after('buyer_ip_hash');
            $table->json('risk_flags')->nullable()->after('risk_fingerprint');
            $table->string('paypal_client_metadata_id', 64)->nullable()->after('risk_flags');
            $table->string('payer_email_hash', 64)->nullable()->after('paypal_client_metadata_id');
            $table->string('payment_exception_status', 32)->nullable()->after('payer_email_hash');
            $table->longText('payment_exception_payload')->nullable()->after('payment_exception_status');

            $table->index(['buyer_email_hash', 'created_at'], 'paypal_b_transactions_buyer_email_time_index');
            $table->index(['buyer_ip_hash', 'created_at'], 'paypal_b_transactions_buyer_ip_time_index');
            $table->index(['risk_fingerprint', 'created_at'], 'paypal_b_transactions_risk_time_index');
        });
    }

    public function down(): void
    {
        Schema::table('paypal_b_transactions', function (Blueprint $table): void {
            $table->dropIndex('paypal_b_transactions_buyer_email_time_index');
            $table->dropIndex('paypal_b_transactions_buyer_ip_time_index');
            $table->dropIndex('paypal_b_transactions_risk_time_index');
            $table->dropColumn([
                'paypal_item_source',
                'buyer_email_hash',
                'buyer_ip_hash',
                'risk_fingerprint',
                'risk_flags',
                'paypal_client_metadata_id',
                'payer_email_hash',
                'payment_exception_status',
                'payment_exception_payload',
            ]);
        });
    }
};
