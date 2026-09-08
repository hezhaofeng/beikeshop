<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paypal_a_transactions', function (Blueprint $table): void {
            $table->string('payment_method', 16)->nullable()->after('currency')->index('paypal_a_transactions_method_index');
        });
    }

    public function down(): void
    {
        Schema::table('paypal_a_transactions', function (Blueprint $table): void {
            $table->dropIndex('paypal_a_transactions_method_index');
            $table->dropColumn('payment_method');
        });
    }
};
