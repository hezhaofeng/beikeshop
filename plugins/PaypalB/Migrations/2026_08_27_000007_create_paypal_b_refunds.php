<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 退款请求和 PayPal 异步结果独立留痕；同一捕获号允许多笔部分退款，但累计额不能超过原收款。
     */
    public function up(): void
    {
        Schema::table('paypal_b_transactions', function (Blueprint $table): void {
            $table->decimal('refunded_amount', 16, 4)->default('0.0000')->after('paid_at');
            $table->string('refund_status', 32)->default('none')->after('refunded_amount');
            $table->string('dispute_status', 32)->nullable()->after('refund_status');
            $table->longText('dispute_payload')->nullable()->after('dispute_status');
            $table->index(['refund_status', 'updated_at'], 'paypal_b_transactions_refund_status_index');
            $table->index(['dispute_status', 'updated_at'], 'paypal_b_transactions_dispute_status_index');
        });

        Schema::create('paypal_b_refunds', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('transaction_id')->index('paypal_b_refunds_transaction_index');
            $table->string('request_id', 64)->unique('paypal_b_refunds_request_id_unique');
            $table->string('idempotency_key', 128)->nullable();
            $table->string('provider_refund_id', 64)->nullable()->unique('paypal_b_refunds_provider_id_unique');
            $table->string('provider_event_id', 127)->nullable()->unique('paypal_b_refunds_provider_event_id_unique');
            $table->decimal('amount', 16, 4);
            $table->char('currency', 3);
            $table->string('source', 16)->default('api');
            $table->string('status', 32)->default('pending')->index('paypal_b_refunds_status_index');
            $table->string('provider_status', 64)->nullable();
            $table->string('note_to_payer', 255)->nullable();
            $table->longText('provider_payload')->nullable();
            $table->boolean('failure_retryable')->nullable();
            $table->string('failure_class', 32)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->unique(['transaction_id', 'idempotency_key'], 'paypal_b_refunds_transaction_idempotency_unique');
            $table->index(['transaction_id', 'status'], 'paypal_b_refunds_transaction_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paypal_b_refunds');

        Schema::table('paypal_b_transactions', function (Blueprint $table): void {
            $table->dropIndex('paypal_b_transactions_refund_status_index');
            $table->dropIndex('paypal_b_transactions_dispute_status_index');
            $table->dropColumn([
                'refunded_amount',
                'refund_status',
                'dispute_status',
                'dispute_payload',
            ]);
        });
    }
};
