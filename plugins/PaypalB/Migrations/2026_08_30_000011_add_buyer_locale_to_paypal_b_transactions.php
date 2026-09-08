<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('paypal_b_transactions', 'buyer_locale')) {
            return;
        }

        Schema::table('paypal_b_transactions', function (Blueprint $table): void {
            // B 站是独立站点，无法从自身会话推断买家语言，只能沿用 A 站下单时的语言。
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
