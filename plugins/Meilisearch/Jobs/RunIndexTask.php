<?php

namespace Plugin\Meilisearch\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Plugin\Meilisearch\Services\IndexTaskService;
use Plugin\Meilisearch\Services\ProductIndexer;

/**
 * 执行后台提交的索引任务，并持续回写进度供管理页面轮询。
 */
class RunIndexTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // 全量索引需要遍历整个商品库，超时按大目录预留，失败由任务表记录而不是无限重试。
    public int $timeout = 7200;

    public function __construct(public string $taskId)
    {
        $this->onQueue('meilisearch');
    }

    public function handle(IndexTaskService $tasks, ProductIndexer $indexer): void
    {
        if (! $tasks->claim($this->taskId)) {
            // 任务已被其他 Worker 领取或已被取消。
            return;
        }

        $task = $tasks->find($this->taskId);
        if (! $task) {
            return;
        }

        try {
            $result = $task['action'] === 'sync'
                ? $this->runSync($tasks, $indexer, $task)
                : $this->runRebuild($tasks, $indexer, $task);

            $tasks->succeed($this->taskId, $result, $this->successMessage($task, $result));
        } catch (\Throwable $exception) {
            $tasks->fail($this->taskId, $exception);
            report($exception);

            throw $exception;
        }
    }

    /**
     * 队列层面失败（超时、Worker 被杀）时同样要释放任务锁。
     */
    public function failed(\Throwable $exception): void
    {
        app(IndexTaskService::class)->fail($this->taskId, $exception);
    }

    /**
     * 全量重建：逐语言构建新索引并原子切换。
     *
     * @param array<string,mixed> $task
     * @return array<string,mixed>
     */
    private function runRebuild(IndexTaskService $tasks, ProductIndexer $indexer, array $task): array
    {
        $catalog = $task['catalog'];
        $locales = $task['locales'];
        $total   = max(1, $indexer->sourceCount($catalog) * max(1, count($locales)));
        $done    = 0;
        $indexes = [];

        foreach ($locales as $position => $locale) {
            $tasks->progress(
                $this->taskId,
                $this->percent($done, $total),
                "正在重建语言 {$locale} 的索引（" . ($position + 1) . '/' . count($locales) . '）',
                $done
            );

            $indexes[$locale] = $indexer->rebuild($catalog, $locale, null, function (int $processed) use ($tasks, &$done, $total, $locale): void {
                $done += $processed;
                $tasks->progress($this->taskId, $this->percent($done, $total), "语言 {$locale} 已写入 {$done} 条商品", $done);
            });
        }

        return [
            'action'    => 'rebuild',
            'catalog'   => $catalog,
            'locales'   => $locales,
            'indexes'   => $indexes,
            'processed' => $done,
        ];
    }

    /**
     * 增量同步：按更新时间游标补齐活动索引中的变更。
     *
     * @param array<string,mixed> $task
     * @return array<string,mixed>
     */
    private function runSync(IndexTaskService $tasks, ProductIndexer $indexer, array $task): array
    {
        $catalog = $task['catalog'];
        $locales = $task['locales'];
        $done    = 0;
        $counts  = [];

        foreach ($locales as $position => $locale) {
            $tasks->progress(
                $this->taskId,
                $this->percent($position, max(1, count($locales))),
                "正在增量同步语言 {$locale}",
                $done
            );

            $counts[$locale] = $indexer->syncChanged($catalog, $locale, null, 120, function () use ($tasks, &$done, $locale): void {
                $done++;
                // 增量总量未知，进度停在阶段值上，用已同步条数反映实际进展。
                $tasks->progress($this->taskId, 50, "语言 {$locale} 已同步 {$done} 条商品", $done);
            });
        }

        return [
            'action'    => 'sync',
            'catalog'   => $catalog,
            'locales'   => $locales,
            'counts'    => $counts,
            'processed' => $done,
        ];
    }

    /**
     * @param array<string,mixed> $task
     * @param array<string,mixed> $result
     */
    private function successMessage(array $task, array $result): string
    {
        $processed = (int) ($result['processed'] ?? 0);

        return $task['action'] === 'sync'
            ? "增量同步完成，共处理 {$processed} 条商品"
            : "全量索引完成，共写入 {$processed} 条商品";
    }

    private function percent(int $done, int $total): int
    {
        return (int) max(3, min(99, round($done / max(1, $total) * 100)));
    }
}
