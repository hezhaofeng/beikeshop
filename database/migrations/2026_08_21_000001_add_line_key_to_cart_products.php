<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('cart_products', 'line_key')) {
            return;
        }

        Schema::table('cart_products', function (Blueprint $table): void {
            $table->string('line_key', 64)->default('')->after('product_sku');
            $table->index(
                ['customer_id', 'session_id', 'product_id', 'product_sku', 'line_key'],
                'cart_products_line_identity'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('cart_products', 'line_key')) {
            return;
        }

        Schema::table('cart_products', function (Blueprint $table): void {
            $table->dropIndex('cart_products_line_identity');
            $table->dropColumn('line_key');
        });
    }
};
