<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paypal_b_transactions', function (Blueprint $table): void {
            // 原始 PayPal 响应可能包含付款人或地址信息，改为长文本后可使用 encrypted cast。
            $table->longText('provider_payload')->nullable()->change();
            $table->string('callback_status', 16)->default('idle')->after('callback_attempts');
            $table->timestamp('callback_next_attempt_at')->nullable()->after('callback_status');
            $table->timestamp('callback_last_sent_at')->nullable()->after('callback_next_attempt_at');
            $table->index(['order_number', 'status'], 'paypal_b_transactions_order_status_index');
            $table->index(['callback_status', 'callback_next_attempt_at'], 'paypal_b_transactions_callback_due_index');
        });

        // 兼容已存在的 JSON 响应：encrypted:array 读取的是“加密后的 JSON 字符串”。
        DB::table('paypal_b_transactions')
            ->whereNotNull('provider_payload')
            ->select(['id', 'provider_payload'])
            ->orderBy('id')
            ->each(function (object $row): void {
                $payload = (string) $row->provider_payload;
                if ($payload === '' || ! is_array(json_decode($payload, true))) {
                    return;
                }

                DB::table('paypal_b_transactions')
                    ->where('id', $row->id)
                    ->update(['provider_payload' => Crypt::encryptString($payload)]);
            });

        Schema::create('paypal_b_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id')->index('paypal_b_webhook_events_account_index');
            $table->string('paypal_event_id', 127);
            $table->string('event_type', 100)->nullable();
            $table->string('status', 16)->default('received')->index('paypal_b_webhook_events_status_index');
            $table->unsignedBigInteger('transaction_id')->nullable()->index('paypal_b_webhook_events_transaction_index');
            $table->longText('payload');
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'paypal_event_id'], 'paypal_b_webhook_events_account_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paypal_b_webhook_events');

        Schema::table('paypal_b_transactions', function (Blueprint $table): void {
            $table->dropIndex('paypal_b_transactions_order_status_index');
            $table->dropIndex('paypal_b_transactions_callback_due_index');
            $table->dropColumn([
                'callback_status',
                'callback_next_attempt_at',
                'callback_last_sent_at',
            ]);
        });
    }
};
