<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 安装在建表后中断时保留已建表，重试只创建缺失部分并写回迁移记录。
        $this->assertExistingTablesCompatible();

        if (! Schema::hasTable('product_customization_templates')) {
            Schema::create('product_customization_templates', function (Blueprint $table): void {
                $table->id();
                $table->json('name');
                $table->unsignedInteger('version')->default(1);
                $table->integer('priority')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->index(['active', 'priority']);
            });
        }

        if (! Schema::hasTable('product_customization_fields')) {
            Schema::create('product_customization_fields', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('template_id')
                    ->constrained('product_customization_templates')
                    ->cascadeOnDelete();
                $table->string('field_key', 64);
                $table->json('label');
                $table->string('type', 20)->default('text');
                $table->json('placeholder')->nullable();
                $table->json('options')->nullable();
                $table->unsignedInteger('max_length')->nullable();
                $table->boolean('required')->default(false);
                $table->boolean('active')->default(true);
                $table->integer('sort')->default(0);
                $table->timestamps();
                $table->unique(['template_id', 'field_key']);
                $table->index(['template_id', 'active', 'sort']);
            });
        }

        if (! Schema::hasTable('product_customization_template_categories')) {
            Schema::create('product_customization_template_categories', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('template_id')
                    ->constrained('product_customization_templates')
                    ->cascadeOnDelete();
                $table->unsignedBigInteger('category_id');
                $table->timestamps();
                $table->unique(['template_id', 'category_id']);
                $table->index('category_id');
            });
        }

        if (! Schema::hasTable('cart_product_customizations')) {
            Schema::create('cart_product_customizations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('cart_product_id')->unique()->constrained('cart_products')->cascadeOnDelete();
                $table->foreignId('template_id')->constrained('product_customization_templates');
                $table->unsignedInteger('template_version');
                $table->string('line_key', 64);
                $table->json('fields');
                $table->json('values');
                $table->timestamps();
                $table->index(['template_id', 'line_key']);
            });
        }

        if (! Schema::hasTable('order_product_customizations')) {
            Schema::create('order_product_customizations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('order_product_id')->unique()->constrained('order_products')->cascadeOnDelete();
                $table->foreignId('template_id')->constrained('product_customization_templates');
                $table->unsignedInteger('template_version');
                $table->string('line_key', 64);
                $table->json('fields');
                $table->json('values');
                $table->timestamps();
                $table->index(['template_id', 'line_key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_product_customizations');
        Schema::dropIfExists('cart_product_customizations');
        Schema::dropIfExists('product_customization_template_categories');
        Schema::dropIfExists('product_customization_fields');
        Schema::dropIfExists('product_customization_templates');
    }

    /**
     * 防止复用来源不明或字段不完整的同名表，避免后续运行时数据损坏。
     */
    private function assertExistingTablesCompatible(): void
    {
        $tables = [
            'product_customization_templates'           => ['id', 'name', 'version', 'priority', 'active'],
            'product_customization_fields'              => ['id', 'template_id', 'field_key', 'label', 'type', 'required', 'active', 'sort'],
            'product_customization_template_categories' => ['id', 'template_id', 'category_id'],
            'cart_product_customizations'               => ['id', 'cart_product_id', 'template_id', 'template_version', 'line_key', 'fields', 'values'],
            'order_product_customizations'              => ['id', 'order_product_id', 'template_id', 'template_version', 'line_key', 'fields', 'values'],
        ];

        foreach ($tables as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    throw new \RuntimeException("现有表 {$table} 缺少 {$column} 字段，不能安全继续安装商品定制插件。");
                }
            }
        }
    }
};
