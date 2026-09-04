<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('custom_mail_payment_info_rotation_states')) {
            Schema::create('custom_mail_payment_info_rotation_states', function (Blueprint $table): void {
                $table->string('payment_method_code', 64)->primary();
                $table->unsignedBigInteger('next_position')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('custom_mail_payment_info_assignments')) {
            Schema::create('custom_mail_payment_info_assignments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('order_id')->unique();
                $table->string('payment_method_code', 64)->index();
                $table->string('profile_id', 100);
                $table->string('profile_name', 100);
                $table->longText('profile_html');
                $table->timestamps();
            });
        }

        foreach (['offline_transfer', 'western_union'] as $paymentCode) {
            DB::table('custom_mail_payment_info_rotation_states')->updateOrInsert(
                ['payment_method_code' => $paymentCode],
                ['next_position' => 0, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_mail_payment_info_assignments');
        Schema::dropIfExists('custom_mail_payment_info_rotation_states');
    }
};
