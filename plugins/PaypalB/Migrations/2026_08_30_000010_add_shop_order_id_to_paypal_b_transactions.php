<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('paypal_b_transactions', 'shop_order_id')) {
            return;
        }

        Schema::table('paypal_b_transactions', function (Blueprint $table): void {
            // 唯一索引保证一笔收款只会生成一条 B 站订单，重复回调或 Webhook 不会重复建单。
            $table->unsignedBigInteger('shop_order_id')
                ->nullable()
                ->after('paypal_capture_id')
                ->comment('B 站影子订单 ID');
            $table->unique('shop_order_id', 'paypal_b_transactions_shop_order_id_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('paypal_b_transactions', 'shop_order_id')) {
            return;
        }

        Schema::table('paypal_b_transactions', function (Blueprint $table): void {
            $table->dropUnique('paypal_b_transactions_shop_order_id_unique');
            $table->dropColumn('shop_order_id');
        });
    }
};
