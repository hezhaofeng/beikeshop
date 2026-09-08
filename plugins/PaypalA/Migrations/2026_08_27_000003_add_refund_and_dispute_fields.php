<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paypal_a_transactions', function (Blueprint $table): void {
            $table->decimal('refunded_amount', 16, 4)->default('0.0000')->after('paid_at');
            $table->string('refund_status', 32)->default('none')->after('refunded_amount');
            $table->string('dispute_status', 32)->nullable()->after('refund_status');
        });
    }

    public function down(): void
    {
        Schema::table('paypal_a_transactions', function (Blueprint $table): void {
            $table->dropColumn(['refunded_amount', 'refund_status', 'dispute_status']);
        });
    }
};
