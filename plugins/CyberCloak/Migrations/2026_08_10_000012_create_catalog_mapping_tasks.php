<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 保存后台提交的长耗时映射任务，供管理页面轮询执行阶段和最终结果。
     */
    public function up(): void
    {
        if (Schema::hasTable('catalog_mapping_tasks')) {
            return;
        }

        Schema::create('catalog_mapping_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type', 32)->default('product');
            $table->string('action', 32)->default('rebuild');
            $table->string('mode', 32)->nullable();
            $table->string('mapping_version', 64)->nullable()->index();
            $table->boolean('sync_routes')->default(false);
            $table->boolean('force')->default(false);
            // 只有 queued/running 任务保留 product，唯一索引在并发请求下也能阻止重复全量映射。
            $table->string('active_key', 32)->nullable()->unique();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->string('status', 16)->default('queued')->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('message', 255)->default('等待队列执行');
            $table->longText('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
        });
    }

    /**
     * 删除任务状态记录，不影响已经生成的商品、SKU 和路由映射。
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_mapping_tasks');
    }
};
