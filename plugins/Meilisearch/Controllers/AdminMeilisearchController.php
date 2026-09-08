<?php

namespace Plugin\Meilisearch\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Plugin\Meilisearch\Jobs\RunIndexTask;
use Plugin\Meilisearch\Models\IndexState;
use Plugin\Meilisearch\Services\CatalogContext;
use Plugin\Meilisearch\Services\IndexTaskService;
use Plugin\Meilisearch\Services\MeilisearchClient;
use Plugin\Meilisearch\Services\ProductIndexer;
use Plugin\Meilisearch\Services\Settings;

/**
 * 插件编辑页的索引管理面板接口。
 *
 * 索引构建统一走队列任务，后台只负责提交和轮询，避免长耗时请求被网关中断。
 */
class AdminMeilisearchController
{
    /**
     * 汇总服务健康状态、各商品库索引覆盖情况和最近一次任务。
     */
    public function status(IndexTaskService $tasks, MeilisearchClient $client, ProductIndexer $indexer): mixed
    {
        $health = ['ok' => false, 'message' => ''];

        try {
            $result            = $client->health();
            $health['ok']      = ($result['status'] ?? '') === 'available';
            $health['message'] = (string) ($result['status'] ?? '未知状态');
        } catch (\Throwable $exception) {
            $health['message'] = $exception->getMessage();
        }

        $indexes = [];
        if ($health['ok']) {
            try {
                $indexes = $client->listIndexes();
            } catch (\Throwable $exception) {
                // 索引列表读取失败不影响健康状态和数据库侧任务信息展示。
                Log::warning('Meilisearch 读取索引列表失败', ['message' => $exception->getMessage()]);
            }
        }

        return json_success('索引状态已更新', [
            'health'          => $health,
            'host'            => Settings::host(),
            'search_enabled'  => Settings::searchEnabled(),
            'mysql_fallback'  => Settings::mysqlFallback(),
            'cyber_cloak'     => CatalogContext::cyberCloakAvailable(),
            'locales'         => Settings::locales(),
            'catalogs'        => $this->catalogSummary($client, $indexer, $health['ok'], $indexes),
            'task'            => $tasks->latest(),
        ]);
    }

    /**
     * 删除一个不在活动状态中的插件索引。
     * 索引名必须匹配当前配置的商品库和语言，避免借此接口删除其他业务索引。
     */
    public function deleteIndex(Request $request, MeilisearchClient $client): mixed
    {
        $data  = $request->validate(['index' => 'required|string|max:250']);
        $index = (string) $data['index'];

        $parts = $this->indexParts($index);
        if (! $parts || ! in_array($parts['catalog'], CatalogContext::catalogs(), true)) {
            return json_fail('索引不属于当前 Meilisearch 插件', [], 422);
        }

        if (IndexState::query()->where('active_index', $index)->exists()) {
            return json_fail('当前使用中的索引不能删除', [], 422);
        }

        try {
            $client->waitForTask($client->deleteIndex($index));
        } catch (\Throwable $exception) {
            if (! str_contains(strtolower($exception->getMessage()), 'index_not_found')) {
                return json_fail($exception->getMessage());
            }
        }

        return json_success('索引已删除', ['index' => $index]);
    }

    /**
     * 提交全量索引任务：从数据库读取商品重建新索引，完成后原子切换。
     */
    public function rebuild(Request $request, IndexTaskService $tasks): mixed
    {
        return $this->submit($request, $tasks, 'rebuild');
    }

    /**
     * 提交增量同步任务：只补齐活动索引中已变更的商品。
     */
    public function sync(Request $request, IndexTaskService $tasks): mixed
    {
        return $this->submit($request, $tasks, 'sync');
    }

    /**
     * 返回任务状态给管理页面轮询。
     */
    public function task(string $task, IndexTaskService $tasks): mixed
    {
        $data = $tasks->find($task);
        if (! $data) {
            return json_fail('索引任务不存在', [], 404);
        }

        return json_success('索引任务状态已更新', $data);
    }

    /**
     * 队列不可用导致任务卡在排队状态时，释放任务锁以便重新提交。
     */
    public function cancelTask(string $task, IndexTaskService $tasks): mixed
    {
        if (! $tasks->cancel($task)) {
            return json_fail('任务已结束，无需取消');
        }

        return json_success('索引任务已取消', $tasks->find($task) ?? []);
    }

    /**
     * 创建任务并投递队列；任务锁冲突时返回当前正在执行的任务供前端继续轮询。
     */
    private function submit(Request $request, IndexTaskService $tasks, string $action): mixed
    {
        $data = $request->validate([
            'catalog'   => 'nullable|in:real,public',
            'locales'   => 'nullable|array',
            'locales.*' => 'string|max:32',
        ]);

        $catalog = (string) ($data['catalog'] ?? CatalogContext::REAL);
        if (! in_array($catalog, CatalogContext::catalogs(), true)) {
            return json_fail('当前站点没有启用该商品库');
        }

        try {
            $task = $tasks->create($action, $catalog, (array) ($data['locales'] ?? []), auth()->id() ? (int) auth()->id() : null);
            RunIndexTask::dispatch($task['id']);
            Log::info('Meilisearch 后台提交索引任务', [
                'task_id' => $task['id'],
                'action'  => $action,
                'catalog' => $catalog,
                'locales' => $task['locales'],
            ]);

            return json_success($action === 'sync' ? '增量同步任务已提交' : '全量索引任务已提交', $task);
        } catch (\Throwable $exception) {
            return json_fail($exception->getMessage(), $tasks->active() ?? []);
        }
    }

    /**
     * 逐个商品库统计索引文档数和数据库商品数，便于发现索引缺失或滞后。
     *
     * @return array<int,array<string,mixed>>
     */
    private function catalogSummary(MeilisearchClient $client, ProductIndexer $indexer, bool $healthy, array $indexes = []): array
    {
        $states  = IndexState::query()->get()->keyBy(fn ($state) => $state->catalog . '|' . $state->locale);
        $summary = [];

        foreach (CatalogContext::catalogs() as $catalog) {
            $sourceCount = null;

            try {
                $sourceCount = $indexer->sourceCount($catalog);
            } catch (\Throwable $exception) {
                // 展示库连接不可用时仍要展示其余商品库的状态。
                Log::warning('Meilisearch 读取商品总数失败', ['catalog' => $catalog, 'message' => $exception->getMessage()]);
            }

            // 保留历史语言索引，避免语言停用后无法在后台看到或清理旧索引。
            $localeCodes = Settings::locales();
            foreach ($states as $state) {
                if ($state->catalog === $catalog && $state->locale) {
                    $localeCodes[] = (string) $state->locale;
                }
            }
            foreach ($indexes as $index) {
                $parts = $this->indexParts((string) ($index['uid'] ?? ''));
                if ($parts && $parts['catalog'] === $catalog) {
                    $localeCodes[] = $parts['locale'];
                }
            }
            $localeCodes = array_values(array_unique($localeCodes));

            $locales = [];
            foreach ($localeCodes as $locale) {
                $state     = $states->get($catalog . '|' . $locale);
                $documents = null;

                if ($healthy && $state?->active_index) {
                    try {
                        $documents = (int) ($client->indexStats($state->active_index)['numberOfDocuments'] ?? 0);
                    } catch (\Throwable) {
                        // 索引刚被删除或正在重建时统计不可用，页面显示为未知。
                    }
                }

                $localeIndexes = [];
                foreach ($indexes as $index) {
                    $uid   = (string) ($index['uid'] ?? '');
                    $parts = $this->indexParts($uid);
                    if (! $parts || $parts['catalog'] !== $catalog || $parts['locale'] !== $locale) {
                        continue;
                    }

                    $localeIndexes[] = $this->indexSummary($client, $index, $uid, $state?->active_index, $healthy);
                }

                // 活动索引被外部删除时仍保留其名称，方便管理员发现状态不一致。
                if ($state?->active_index && ! in_array($state->active_index, array_column($localeIndexes, 'uid'), true)) {
                    $localeIndexes[] = [
                        'uid'        => $state->active_index,
                        'active'     => true,
                        'exists'     => false,
                        'documents'  => null,
                        'updated_at' => null,
                    ];
                }
                usort($localeIndexes, fn (array $left, array $right): int => (int) $right['active'] <=> (int) $left['active'] ?: strcmp($left['uid'], $right['uid']));

                $locales[] = [
                    'locale'          => $locale,
                    'active_index'    => $state?->active_index,
                    'documents'       => $documents,
                    'last_updated_at' => $state?->last_updated_at?->toDateTimeString(),
                    'indexes'         => $localeIndexes,
                ];
            }

            $summary[] = [
                'catalog'      => $catalog,
                'label'        => $catalog === CatalogContext::PUBLIC ? '展示商品库' : '真实商品库',
                'connection'   => CatalogContext::connection($catalog),
                'source_count' => $sourceCount,
                'locales'      => $locales,
            ];
        }

        return $summary;
    }

    /** @return array<string,mixed> */
    private function indexSummary(MeilisearchClient $client, array $index, string $uid, ?string $active, bool $healthy): array
    {
        $documents = array_key_exists('numberOfDocuments', $index) ? (int) $index['numberOfDocuments'] : null;
        if ($documents === null && $healthy) {
            try {
                $documents = (int) ($client->indexStats($uid)['numberOfDocuments'] ?? 0);
            } catch (\Throwable) {
                // 索引可能正在删除或尚未完成创建，保留未知状态。
            }
        }

        return [
            'uid'        => $uid,
            'active'     => $uid === $active,
            'exists'     => true,
            'documents'  => $documents,
            'updated_at' => $index['updatedAt'] ?? null,
        ];
    }

    /** @return array{catalog:string,locale:string}|null */
    private function indexParts(string $index): ?array
    {
        $prefix = Settings::indexPrefix() . '_products_';
        foreach ([CatalogContext::REAL, CatalogContext::PUBLIC] as $catalog) {
            $catalogPrefix = $prefix . $catalog . '_';
            if (! str_starts_with($index, $catalogPrefix)) {
                continue;
            }

            $locale = preg_replace('/_v[0-9]+$/', '', substr($index, strlen($catalogPrefix)));
            if ($locale !== '' && preg_match('/^[a-zA-Z0-9_-]+$/', $locale)) {
                return ['catalog' => $catalog, 'locale' => $locale];
            }
        }

        return null;
    }
}
