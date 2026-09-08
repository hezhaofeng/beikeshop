<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('paypal_b_transactions', 'source')) {
            return;
        }

        Schema::table('paypal_b_transactions', function (Blueprint $table): void {
            // bridge：A 站跨站订单，需要签名回调并生成影子订单；
            // local：B 站自营订单，已有真实订单记录，不回调也不建影子单。
            $table->string('source', 16)
                ->default('bridge')
                ->after('order_number')
                ->index('paypal_b_transactions_source_index')
                ->comment('交易来源：bridge=跨站, local=B 站自营');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('paypal_b_transactions', 'source')) {
            return;
        }

        Schema::table('paypal_b_transactions', function (Blueprint $table): void {
            $table->dropIndex('paypal_b_transactions_source_index');
            $table->dropColumn('source');
        });
    }
};
