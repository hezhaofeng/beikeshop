<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 账号池与跨站会话在 B 站独立保存，PayPal 密钥不会写入 A 站数据库。
     */
    public function up(): void
    {
        Schema::create('paypal_b_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique('paypal_b_accounts_name_unique');
            $table->string('account_type', 32)->index('paypal_b_accounts_type_index');
            $table->string('client_id', 255)->nullable();
            $table->text('client_secret')->nullable();
            $table->string('webhook_id', 255)->nullable();
            $table->string('recipient_name', 255)->nullable();
            $table->string('recipient_email', 255)->nullable();
            $table->string('paypal_me_url', 1000)->nullable();
            $table->string('supported_currencies', 255)->default('*');
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('active')->default(true);
            $table->timestamp('last_selected_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('failure_cooldown_until')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['active', 'priority'], 'paypal_b_accounts_rotation_index');
        });

        Schema::create('paypal_b_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('transaction_id', 64)->unique('paypal_b_transactions_transaction_id_unique');
            $table->string('public_token', 96)->unique('paypal_b_transactions_public_token_unique');
            $table->unsignedBigInteger('account_id')->nullable()->index('paypal_b_transactions_account_id_index');
            $table->string('reference', 64)->unique('paypal_b_transactions_reference_unique');
            $table->string('order_number', 128);
            $table->decimal('amount', 16, 4);
            $table->char('currency', 3);
            $table->text('callback_url');
            $table->string('mode', 32);
            $table->string('status', 32)->default('creating')->index('paypal_b_transactions_status_index');
            $table->string('paypal_order_id', 64)->nullable()->unique('paypal_b_transactions_order_id_unique');
            $table->string('paypal_capture_id', 64)->nullable()->unique('paypal_b_transactions_capture_id_unique');
            $table->text('approval_url')->nullable();
            $table->string('provider_status', 64)->nullable();
            $table->json('bridge_request')->nullable();
            $table->json('provider_payload')->nullable();
            $table->json('callback_payload')->nullable();
            $table->unsignedInteger('callback_attempts')->default(0);
            $table->text('callback_last_error')->nullable();
            $table->timestamp('expires_at')->nullable()->index('paypal_b_transactions_expires_at_index');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status'], 'paypal_b_transactions_account_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paypal_b_transactions');
        Schema::dropIfExists('paypal_b_accounts');
    }
};
