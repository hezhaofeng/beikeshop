<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paypal_b_rotation_states', function (Blueprint $table): void {
            $table->id();
            $table->string('pool_key', 64)->unique('paypal_b_rotation_states_pool_key_unique');
            $table->unsignedBigInteger('last_account_id')->nullable();
            $table->timestamps();
        });

        DB::table('paypal_b_rotation_states')->insert([
            'pool_key'        => 'automatic_api',
            'last_account_id' => null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('paypal_b_rotation_states');
    }
};
