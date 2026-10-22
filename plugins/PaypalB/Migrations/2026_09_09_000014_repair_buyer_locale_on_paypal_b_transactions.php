<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 000011 在部分环境只写入了迁移记录，实际列未创建；这里做幂等结构修复。
     */
    public function up(): void
    {
        if (Schema::hasColumn('paypal_b_transactions', 'buyer_locale')) {
            return;
        }

        Schema::table('paypal_b_transactions', function (Blueprint $table): void {
            $table->string('buyer_locale', 16)
                ->nullable()
                ->after('currency')
                ->comment('A 站买家语言');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('paypal_b_transactions', 'buyer_locale')) {
            return;
        }

        Schema::table('paypal_b_transactions', function (Blueprint $table): void {
            $table->dropColumn('buyer_locale');
        });
    }
};
