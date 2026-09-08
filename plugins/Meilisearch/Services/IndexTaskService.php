<?php

namespace Plugin\Meilisearch\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 管理后台提交的索引任务。
 *
 * 全量索引要遍历整个商品库并等待 Meilisearch 写入任务完成，耗时远超 HTTP 超时，
 * 因此后台只负责创建任务记录并轮询进度，真正的构建由队列 Worker 执行。
 */
class IndexTaskService
{
    private const ACTIVE_STATUSES = ['queued', 'running'];

    private const ACTIONS = ['rebuild', 'sync'];

    /**
     * 创建索引任务；同一时间只允许一个任务运行，避免并发重建互相覆盖活动索引。
     *
     * @param array<int,string> $locales
     * @return array<string,mixed>
     */
    public function create(string $action, string $catalog, array $locales, ?int $adminId): array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new RuntimeException('索引任务动作无效');
        }
        CatalogContext::assertCatalog($catalog);

        $locales = $this->normalizeLocales($locales);
        if ($this->active()) {
            throw new RuntimeException('已有索引任务正在执行，请等待任务完成');
        }

        $id = (string) Str::uuid();

        try {
            $this->table()->insert([
                'id'         => $id,
                'action'     => $action,
                'catalog'    => $catalog,
                'locales'    => implode(',', $locales),
                // 唯一约束覆盖检查与插入之间的竞争窗口，并发提交时只有一个任务能落库。
                'active_key' => 'index',
                'admin_id'   => $adminId,
                'status'     => 'queued',
                'progress'   => 0,
                'processed'  => 0,
                'message'    => '等待队列执行',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            throw new RuntimeException('已有索引任务正在执行，请等待任务完成', previous: $exception);
        }

        return $this->find($id) ?? throw new RuntimeException('索引任务创建失败');
    }

    /**
     * @return array<string,mixed>|null
     */
    public function latest(): ?array
    {
        $row = $this->table()->orderByDesc('updated_at')->orderByDesc('created_at')->first();

        return $row ? $this->serialize($row) : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(string $id): ?array
    {
        $row = $this->table()->where('id', $id)->first();

        return $row ? $this->serialize($row) : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function active(): ?array
    {
        $row = $this->table()
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->orderByDesc('created_at')
            ->first();

        return $row ? $this->serialize($row) : null;
    }

    /**
     * 原子领取排队任务，防止重复 Worker 同时构建同一份索引。
     */
    public function claim(string $id): bool
    {
        return $this->table()
            ->where('id', $id)
            ->where('status', 'queued')
            ->update([
                'status'     => 'running',
                'progress'   => 3,
                'message'    => '正在启动索引任务',
                'started_at' => now(),
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * 更新任务进度；商品总数在任务开始时已知，进度按已处理条数折算。
     */
    public function progress(string $id, int $progress, string $message, int $processed = null): void
    {
        $values = [
            'progress'   => max(0, min(99, $progress)),
            'message'    => Str::limit($message, 250, ''),
            'updated_at' => now(),
        ];
        if ($processed !== null) {
            $values['processed'] = max(0, $processed);
        }

        $this->table()->where('id', $id)->where('status', 'running')->update($values);
    }

    /**
     * @param array<string,mixed> $result
     */
    public function succeed(string $id, array $result, string $message): void
    {
        $this->table()->where('id', $id)->update([
            'active_key'  => null,
            'status'      => 'succeeded',
            'progress'    => 100,
            'processed'   => (int) ($result['processed'] ?? 0),
            'message'     => Str::limit($message, 250, ''),
            'result'      => json_encode($result, JSON_UNESCAPED_UNICODE),
            'error'       => null,
            'finished_at' => now(),
            'updated_at'  => now(),
        ]);
    }

    /**
     * 保存可供后台展示的错误摘要，完整堆栈仍由日志和失败任务表保留。
     */
    public function fail(string $id, \Throwable $exception): void
    {
        $this->table()->where('id', $id)->whereIn('status', self::ACTIVE_STATUSES)->update([
            'active_key'  => null,
            'status'      => 'failed',
            'message'     => '索引任务失败',
            'error'       => Str::limit($exception->getMessage(), 4000, ''),
            'finished_at' => now(),
            'updated_at'  => now(),
        ]);
    }

    /**
     * 队列不可用时任务会一直停留在 queued，这里允许后台释放锁重新提交。
     */
    public function cancel(string $id): bool
    {
        return $this->table()
            ->where('id', $id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->update([
                'active_key'  => null,
                'status'      => 'failed',
                'message'     => '任务已被管理员取消',
                'finished_at' => now(),
                'updated_at'  => now(),
            ]) === 1;
    }

    /**
     * @param array<int,string> $locales
     * @return array<int,string>
     */
    private function normalizeLocales(array $locales): array
    {
        $available = Settings::locales();
        $locales   = array_values(array_intersect($available, array_filter(array_map('strval', $locales))));

        return $locales ?: $available;
    }

    protected function table(): Builder
    {
        return DB::table('meilisearch_index_tasks');
    }

    /**
     * @return array<string,mixed>
     */
    private function serialize(object $row): array
    {
        $result = json_decode((string) ($row->result ?? ''), true);

        return [
            'id'          => (string) $row->id,
            'action'      => (string) $row->action,
            'catalog'     => (string) $row->catalog,
            'locales'     => array_values(array_filter(explode(',', (string) $row->locales))),
            'status'      => (string) $row->status,
            'progress'    => (int) $row->progress,
            'processed'   => (int) $row->processed,
            'message'     => (string) $row->message,
            'result'      => is_array($result) ? $result : null,
            'error'       => $row->error ? (string) $row->error : null,
            'started_at'  => $row->started_at,
            'finished_at' => $row->finished_at,
            'created_at'  => $row->created_at,
            'updated_at'  => $row->updated_at,
        ];
    }
}
