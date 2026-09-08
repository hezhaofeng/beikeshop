<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 保存后台提交的索引任务，全量重建耗时远超 HTTP 超时，必须交给队列执行并轮询进度。
     */
    public function up(): void
    {
        if (Schema::hasTable('meilisearch_index_tasks')) {
            return;
        }

        Schema::create('meilisearch_index_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('action', 32)->default('rebuild');
            $table->string('catalog', 16)->default('real');
            $table->string('locales', 255)->nullable();
            // 只有 queued/running 任务保留固定值，唯一索引可阻止并发请求重复提交全量索引。
            $table->string('active_key', 32)->nullable()->unique();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->string('status', 16)->default('queued')->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('message', 255)->default('等待队列执行');
            $table->unsignedBigInteger('processed')->default(0);
            $table->longText('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    /**
     * 只删除任务状态记录，已经建立的 Meilisearch 索引不受影响。
     */
    public function down(): void
    {
        Schema::dropIfExists('meilisearch_index_tasks');
    }
};
