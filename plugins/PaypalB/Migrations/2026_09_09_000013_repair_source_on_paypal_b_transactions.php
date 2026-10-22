<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 000012 可能已在 source 字段加入前执行过；用新迁移修复已记录但结构不完整的数据库。
     */
    public function up(): void
    {
        if (Schema::hasColumn('paypal_b_transactions', 'source')) {
            return;
        }

        Schema::table('paypal_b_transactions', function (Blueprint $table): void {
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
