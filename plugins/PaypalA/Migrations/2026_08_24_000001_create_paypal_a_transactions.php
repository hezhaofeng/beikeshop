<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A 站只保存本地订单与 B 站支付会话的对应关系，不保存 PayPal 收款凭据。
     */
    public function up(): void
    {
        Schema::create('paypal_a_transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->index('paypal_a_transactions_order_id_index');
            $table->string('reference', 64)->unique('paypal_a_transactions_reference_unique');
            $table->string('b_transaction_id', 64)->nullable()->unique('paypal_a_transactions_b_transaction_id_unique');
            $table->decimal('amount', 16, 4);
            $table->char('currency', 3);
            $table->string('status', 32)->default('initiating')->index('paypal_a_transactions_status_index');
            $table->timestamp('expires_at')->nullable()->index('paypal_a_transactions_expires_at_index');
            $table->json('request_payload')->nullable();
            $table->json('b_response')->nullable();
            $table->json('callback_payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paypal_a_transactions');
    }
};
