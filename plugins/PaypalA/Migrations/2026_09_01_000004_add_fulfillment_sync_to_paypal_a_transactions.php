<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 履约证据同步原先是即发即忘的同步 HTTP，失败后没有任何可重投的依据。
     * 这些字段让 A 到 B 的同步具备与 B 到 A 回调同级的重试与补偿能力。
     */
    public function up(): void
    {
        Schema::table('paypal_a_transactions', function (Blueprint $table): void {
            $table->string('fulfillment_status', 32)->default('idle')->after('dispute_status');
            // 已成功送达的证据摘要。发货数据未变时不重复打扰 B 站。
            $table->string('fulfillment_digest', 64)->nullable()->after('fulfillment_status');
            $table->unsignedInteger('fulfillment_attempts')->default(0)->after('fulfillment_digest');
            $table->timestamp('fulfillment_next_attempt_at')->nullable()->after('fulfillment_attempts');
            $table->timestamp('fulfillment_last_sent_at')->nullable()->after('fulfillment_next_attempt_at');
            $table->text('fulfillment_last_error')->nullable()->after('fulfillment_last_sent_at');

            $table->index(
                ['fulfillment_status', 'fulfillment_next_attempt_at'],
                'paypal_a_transactions_fulfillment_dispatch_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('paypal_a_transactions', function (Blueprint $table): void {
            $table->dropIndex('paypal_a_transactions_fulfillment_dispatch_index');
            $table->dropColumn([
                'fulfillment_status',
                'fulfillment_digest',
                'fulfillment_attempts',
                'fulfillment_next_attempt_at',
                'fulfillment_last_sent_at',
                'fulfillment_last_error',
            ]);
        });
    }
};
