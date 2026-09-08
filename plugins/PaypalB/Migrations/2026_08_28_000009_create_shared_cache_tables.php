<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 后台安装 PaypalB 时也要创建 database cache 所需的共享表。
     * 根目录迁移会在常规部署中覆盖此场景，hasTable 保证两条安装路径可重复执行。
     */
    public function up(): void
    {
        if (! Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table): void {
                // MySQL 5.7 的 utf8mb4 索引上限要求主键不要使用默认 255 长度。
                $table->string('key', 191)->primary();
                $table->mediumText('value');
                $table->integer('expiration')->index();
            });
        }

        if (! Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $table): void {
                $table->string('key', 191)->primary();
                $table->string('owner');
                $table->integer('expiration')->index();
            });
        }
    }

    public function down(): void
    {
        // 共享表可能由根目录迁移或 PaypalA 使用，不随 PaypalB 卸载删除。
    }
};
