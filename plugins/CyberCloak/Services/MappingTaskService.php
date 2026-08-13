<?php

namespace Plugin\CyberCloak\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class MappingTaskService
{
    private const ACTIVE_STATUSES = ['queued', 'running'];

    /**
     * 创建商品映射任务；同一时间只允许一个任务写入映射和发布索引。
     *
     * @return array<string,mixed>
     */
    public function createProductTask(string $action, ?string $mode, ?string $version, bool $syncRoutes, bool $force, ?int $adminId): array
    {
        if (! in_array($action, ['rebuild', 'publish'], true)) {
            throw new RuntimeException('映射任务动作无效');
        }
        if ($action === 'rebuild' && ! in_array($mode, ['exact', 'candidate', 'random'], true)) {
            throw new RuntimeException('映射任务匹配模式无效');
        }
        if ($action === 'publish' && empty($version)) {
            throw new RuntimeException('发布任务缺少映射版本');
        }

        $activeTask = $this->activeTask();
        if ($activeTask) {
            throw new RuntimeException('已有商品映射任务正在执行，请等待任务完成');
        }

        $id = (string) Str::uuid();
        try {
            $this->table()->insert([
                'id'              => $id,
                'type'            => 'product',
                'action'          => $action,
                'mode'            => $mode,
                'mapping_version' => $version,
                'sync_routes'     => $syncRoutes,
                'force'           => $force,
                // MySQL/PostgreSQL 的唯一约束能够覆盖 activeTask() 查询与插入之间的竞争窗口。
                'active_key'      => 'product',
                'admin_id'        => $adminId,
                'status'          => 'queued',
                'progress'        => 0,
                'message'         => '等待队列执行',
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        } catch (QueryException $exception) {
            throw new RuntimeException('已有商品映射任务正在执行，请等待任务完成', previous: $exception);
        }

        return $this->find($id) ?? throw new RuntimeException('映射任务创建失败');
    }

    /**
     * 返回最近提交的任务，页面刷新后可继续轮询未结束任务。
     *
     * @return array<string,mixed>|null
     */
    public function latest(): ?array
    {
        $row = $this->table()->orderByDesc('updated_at')->orderByDesc('created_at')->first();

        return $row ? $this->serialize($row) : null;
    }

    /**
     * 查询单个任务的公开状态，不返回队列负载和数据库配置。
     *
     * @return array<string,mixed>|null
     */
    public function find(string $id): ?array
    {
        $row = $this->table()->where('id', $id)->first();

        return $row ? $this->serialize($row) : null;
    }

    /**
     * 原子领取排队任务，避免重复 Worker 同时执行同一份映射。
     */
    public function claim(string $id): bool
    {
        return $this->table()
            ->where('id', $id)
            ->where('status', 'queued')
            ->update([
                'status'     => 'running',
                'progress'   => 5,
                'message'    => '正在启动商品映射任务',
                'started_at' => now(),
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * 更新任务阶段；映射服务按批处理，进度使用稳定的阶段值而不是不准确的行数估计。
     */
    public function progress(string $id, int $progress, string $message): void
    {
        $this->table()
            ->where('id', $id)
            ->where('status', 'running')
            ->update([
                'progress'   => max(0, min(99, $progress)),
                'message'    => $message,
                'updated_at' => now(),
            ]);
    }

    /**
     * 记录任务完成后的映射版本、统计和路由结果。
     *
     * @param array<string,mixed> $result
     */
    public function succeed(string $id, array $result, string $message): void
    {
        $this->table()->where('id', $id)->update([
            'mapping_version' => $result['version'] ?? null,
            'active_key'      => null,
            'status'          => 'succeeded',
            'progress'        => 100,
            'message'         => $message,
            'result'          => json_encode($result, JSON_UNESCAPED_UNICODE),
            'error'           => null,
            'finished_at'     => now(),
            'updated_at'      => now(),
        ]);
    }

    /**
     * 保存可供后台展示的错误摘要，完整堆栈仍由 Laravel 失败任务和日志保留。
     */
    public function fail(string $id, \Throwable $exception): void
    {
        $this->table()->where('id', $id)->whereIn('status', self::ACTIVE_STATUSES)->update([
            'active_key'  => null,
            'status'      => 'failed',
            'message'     => '商品映射任务失败',
            'error'       => Str::limit($exception->getMessage(), 4000, ''),
            'finished_at' => now(),
            'updated_at'  => now(),
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function activeTask(): ?array
    {
        $row = $this->table()
            ->where('type', 'product')
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->orderByDesc('created_at')
            ->first();

        return $row ? $this->serialize($row) : null;
    }

    protected function table(): Builder
    {
        $connection = (string) config('cyber_cloak.connections.real', 'mysql');

        return DB::connection($connection)->table('catalog_mapping_tasks');
    }

    /**
     * @return array<string,mixed>
     */
    private function serialize(object $row): array
    {
        $result = json_decode((string) ($row->result ?? ''), true);

        return [
            'id'              => (string) $row->id,
            'type'            => (string) $row->type,
            'action'          => (string) $row->action,
            'mode'            => $row->mode ? (string) $row->mode : null,
            'mapping_version' => $row->mapping_version ? (string) $row->mapping_version : null,
            'sync_routes'     => (bool) $row->sync_routes,
            'force'           => (bool) $row->force,
            'status'          => (string) $row->status,
            'progress'        => (int) $row->progress,
            'message'         => (string) $row->message,
            'result'          => is_array($result) ? $result : null,
            'error'           => $row->error ? (string) $row->error : null,
            'started_at'      => $row->started_at,
            'finished_at'     => $row->finished_at,
            'created_at'      => $row->created_at,
            'updated_at'      => $row->updated_at,
        ];
    }
}
