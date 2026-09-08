<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meilisearch_index_states', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 32)->unique();
            $table->string('active_index')->nullable();
            $table->timestamp('last_updated_at')->nullable();
            $table->unsignedBigInteger('last_product_id')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meilisearch_index_states');
    }
};
