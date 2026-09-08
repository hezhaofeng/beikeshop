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
            // A 站真实商品快照也可能包含敏感商品信息，改为长文本后使用 encrypted cast。
            $table->longText('order_items')->change();
            // 保存创建 PayPal Order 时的脱敏/映射快照，重试时不能因后台配置变化而改变幂等请求体。
            $table->longText('paypal_order_items')->nullable()->after('order_items');
        });

        DB::table('paypal_b_transactions')
            ->whereNotNull('order_items')
            ->select(['id', 'order_items'])
            ->orderBy('id')
            ->each(function (object $row): void {
                $value = (string) $row->order_items;
                if ($value === '' || ! is_array(json_decode($value, true))) {
                    return;
                }

                DB::table('paypal_b_transactions')
                    ->where('id', $row->id)
                    ->update(['order_items' => Crypt::encryptString($value)]);
            });
    }

    public function down(): void
    {
        Schema::table('paypal_b_transactions', function (Blueprint $table): void {
            $table->dropColumn('paypal_order_items');
        });
    }
};
