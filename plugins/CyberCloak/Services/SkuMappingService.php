<?php

namespace Plugin\CyberCloak\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class SkuMappingService
{
    private const PRODUCT_BATCH_SIZE      = 100;

    private const CANDIDATE_PRODUCT_LIMIT = 50;

    private const CANDIDATE_SKU_LIMIT     = 100;

    private const EXACT_CANDIDATE_LIMIT   = 100;

    private const PUBLISHED_SKU_INDEX_TABLE = 'catalog_published_sku_mappings';

    private const PUBLISHED_SKU_INDEX_BATCH_SIZE = 500;

    /**
     * 将展示库 SKU 查询限制为当前发布映射，避免前台请求构造全量 SKU ID 的 WHERE IN。
     */
    public function constrainToPublishedPublicSkus(mixed $query, string $skuTable = 'product_skus'): void
    {
        $query->whereExists(function ($builder) use ($skuTable): void {
            $builder->selectRaw('1')
                ->from(self::PUBLISHED_SKU_INDEX_TABLE . ' as published_skus')
                ->whereColumn('published_skus.public_sku_id', $skuTable . '.id')
                ->where('published_skus.active', 1);
        });
    }

    /**
     * 将当前已发布映射写入展示库索引；用于存量已发布版本首次升级后的回填。
     *
     * @return array{version:string,skus:int}
     */
    public function syncPublishedSkuIndex(string $version = null): array
    {
        $main    = DB::connection($this->mainConnection());
        $version = $version ?: (string) $main->table('catalog_mapping_versions')
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->value('version');
        if ($version === '' || ! $main->table('catalog_mapping_versions')
            ->where('version', $version)
            ->where('status', 'published')
            ->exists()) {
            throw new RuntimeException('没有可同步的已发布商品映射');
        }

        $this->replacePublishedSkuIndex($main, $version);

        try {
            $this->pruneInactivePublishedSkuIndex();
        } catch (\Throwable) {
            // 索引已可用，历史记录将在下次同步或发布时继续清理。
        }

        return [
            'version' => $version,
            'skus'    => $this->publishedSkuIndexCount(),
        ];
    }

    /**
     * 汇总后台映射面板需要的版本、待确认 SKU 和路由数量。
     *
     * @return array<string,mixed>
     */
    public function dashboard(string $version = null, int $limit = 100): array
    {
        $main     = DB::connection($this->mainConnection());
        $versions = $main->table('catalog_mapping_versions')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get([
                'version',
                'status',
                'source',
                'product_count',
                'sku_count',
                'pending_count',
                'conflict_count',
                'published_at',
                'created_at',
            ])
            ->map(static fn ($row): array => [
                'version'        => (string) $row->version,
                'status'         => (string) $row->status,
                'source'         => (string) $row->source,
                'product_count'  => (int) $row->product_count,
                'sku_count'      => (int) $row->sku_count,
                'pending_count'  => (int) $row->pending_count,
                'conflict_count' => (int) $row->conflict_count,
                'published_at'   => $row->published_at,
                'created_at'     => $row->created_at,
            ])
            ->values()
            ->all();

        $selectedVersion = $version ?: ($versions[0]['version'] ?? null);
        $selected        = collect($versions)->firstWhere('version', $selectedVersion);
        $items           = $selectedVersion ? $this->pendingDashboardItems($main, $selectedVersion, $limit) : [];

        $routeStats = ['product' => 0, 'category' => 0];

        try {
            if (Schema::hasTable('catalog_route_maps')) {
                $routeStats = array_merge($routeStats, $main->table('catalog_route_maps')
                    ->selectRaw('route_type, COUNT(*) as total')
                    ->groupBy('route_type')
                    ->pluck('total', 'route_type')
                    ->map(fn ($total): int => (int) $total)
                    ->all());
            }
        } catch (\Throwable) {
            // 旧安装尚未执行路由迁移时，面板保留零值并继续显示映射状态。
        }

        return [
            'published_version' => $main->table('catalog_mapping_versions')
                ->where('status', 'published')
                ->orderByDesc('published_at')
                ->value('version'),
            'selected_version'  => $selectedVersion,
            'selected'          => $selected ?: null,
            'versions'          => $versions,
            'items'             => $items,
            'route_stats'       => $routeStats,
        ];
    }

    /**
     * 按展示 SKU、型号或 ID 搜索可用于人工确认的展示记录。
     *
     * @return array<int,array<string,mixed>>
     */
    public function searchPublicSkus(string $keyword = '', int $limit = 20): array
    {
        $limit   = max(1, min($limit, 50));
        $keyword = trim($keyword);
        $query   = DB::connection($this->connection('public'))
            ->table('product_skus as skus')
            ->join('products', 'products.id', '=', 'skus.product_id')
            ->where('skus.active', 1)
            ->where('products.active', 1)
            ->whereNull('products.deleted_at');

        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword): void {
                $builder->where('skus.sku', 'like', '%' . $keyword . '%')
                    ->orWhere('skus.model', 'like', '%' . $keyword . '%');
                if (ctype_digit($keyword)) {
                    $builder->orWhere('skus.id', (int) $keyword);
                }
            });
        }

        return $query
            ->orderBy('skus.id')
            ->limit($limit)
            ->get(['skus.id', 'skus.product_id', 'skus.sku', 'skus.model'])
            ->map(static fn ($row): array => [
                'id'         => (int) $row->id,
                'product_id' => (int) $row->product_id,
                'sku'        => (string) $row->sku,
                'model'      => (string) $row->model,
            ])
            ->all();
    }

    /**
     * 返回版本中需要人工处理的 SKU，并补充真实库和展示库的可读信息。
     *
     * @return array<int,array<string,mixed>>
     */
    private function pendingDashboardItems($main, string $version, int $limit): array
    {
        $limit = max(1, min($limit, 200));
        $rows  = $main->table('catalog_sku_mappings')
            ->where('mapping_version', $version)
            ->whereIn('status', ['pending', 'conflict'])
            ->orderBy('id')
            ->limit($limit)
            ->get([
                'id',
                'real_sku_id',
                'real_product_id',
                'real_sku',
                'public_sku_id',
                'candidate_data',
                'status',
            ]);
        if ($rows->isEmpty()) {
            return [];
        }

        $realSkuIds = $rows->pluck('real_sku_id')->map(fn ($id): int => (int) $id)->all();
        $realSkus   = DB::connection($this->connection('real'))
            ->table('product_skus')
            ->whereIn('id', $realSkuIds)
            ->get(['id', 'sku', 'model'])
            ->keyBy('id');

        $candidateIds      = [];
        $candidateIdsByRow = [];
        foreach ($rows as $row) {
            $candidateData = json_decode((string) $row->candidate_data, true);
            $ids           = array_values(array_unique(array_filter(array_map(
                'intval',
                is_array($candidateData) ? ($candidateData['public_sku_ids'] ?? []) : []
            ), fn (int $id): bool => $id > 0)));
            $candidateIdsByRow[(int) $row->id] = $ids;
            $candidateIds                      = array_merge($candidateIds, $ids);
        }
        $candidateIds = array_values(array_unique($candidateIds));
        $publicSkus   = $candidateIds === [] ? collect() : DB::connection($this->connection('public'))
            ->table('product_skus as skus')
            ->join('products', 'products.id', '=', 'skus.product_id')
            ->whereIn('skus.id', $candidateIds)
            ->where('skus.active', 1)
            ->where('products.active', 1)
            ->whereNull('products.deleted_at')
            ->get(['skus.id', 'skus.product_id', 'skus.sku', 'skus.model'])
            ->keyBy('id');

        return $rows->map(function ($row) use ($realSkus, $candidateIdsByRow, $publicSkus): array {
            $realSku        = $realSkus->get((int) $row->real_sku_id);
            $candidateItems = [];
            foreach ($candidateIdsByRow[(int) $row->id] ?? [] as $candidateId) {
                $candidate        = $publicSkus->get($candidateId);
                $candidateItems[] = [
                    'id'         => $candidateId,
                    'product_id' => $candidate ? (int) $candidate->product_id : 0,
                    'sku'        => $candidate ? (string) $candidate->sku : '',
                    'model'      => $candidate ? (string) $candidate->model : '',
                ];
            }

            return [
                'id'              => (int) $row->id,
                'real_sku_id'     => (int) $row->real_sku_id,
                'real_product_id' => (int) $row->real_product_id,
                'real_sku'        => $realSku ? (string) $realSku->sku : (string) $row->real_sku,
                'real_model'      => $realSku ? (string) $realSku->model : '',
                'public_sku_id'   => $row->public_sku_id ? (int) $row->public_sku_id : null,
                'status'          => (string) $row->status,
                'candidates'      => $candidateItems,
            ];
        })->values()->all();
    }

    /**
     * 批量重建一个映射版本。随机模式首次选择后会从历史随机版本复用映射。
     *
     * @return array{version:string,products:int,skus:int,confirmed:int,pending:int,conflict:int,published:bool}
     */
    public function rebuild(string $mode = 'exact', string $version = null, bool $publish = false, bool $force = false): array
    {
        if (! in_array($mode, ['exact', 'candidate', 'random'], true)) {
            throw new RuntimeException('映射模式只支持 exact、candidate 或 random');
        }

        $version = $version ?: $this->newVersion();
        $main    = DB::connection($this->mainConnection());
        $main->transaction(function () use ($main, $mode, $version): void {
            $main->table('catalog_mapping_versions')->insert([
                'version'    => $version,
                'status'     => 'draft',
                'source'     => $mode,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            // SKU 和商品映射必须在同一事务中写入，避免分批失败留下孤儿记录。
            $result = $main->transaction(fn (): array => $this->buildMappings($main, $mode, $version));
            if ($publish) {
                if (! $force && ($result['pending'] > 0 || $result['conflict'] > 0)) {
                    // 保留草稿版本供确认命令写回，避免一次发布尝试销毁待处理数据。
                    $result['version']   = $version;
                    $result['published'] = false;

                    return $result;
                }
                $this->publish($main, $version);
            }

            $result['version']   = $version;
            $result['published'] = $publish;

            return $result;
        } catch (\Throwable $exception) {
            $main->table('catalog_mapping_versions')->where('version', $version)->delete();

            throw $exception;
        }
    }

    /**
     * 确认一个 pending/conflict SKU，并重算所属商品和版本统计。
     *
     * @return array{version:string,real_sku_id:int,public_sku_id:int,status:string}
     */
    public function confirm(string $version, int $realSkuId, int $publicSkuId): array
    {
        if ($version === '' || $realSkuId < 1 || $publicSkuId < 1) {
            throw new RuntimeException('确认映射必须提供有效的版本、真实 SKU 和展示 SKU');
        }

        $main = DB::connection($this->mainConnection());

        return $main->transaction(function () use ($main, $version, $realSkuId, $publicSkuId): array {
            $mapping = $main->table('catalog_sku_mappings')
                ->where('mapping_version', $version)
                ->where('real_sku_id', $realSkuId)
                ->first();
            if (! $mapping) {
                throw new RuntimeException('指定版本中不存在该真实 SKU 映射');
            }

            $publicSku = DB::connection($this->connection('public'))
                ->table('product_skus as skus')
                ->join('products', 'products.id', '=', 'skus.product_id')
                ->where('skus.id', $publicSkuId)
                ->where('skus.active', 1)
                ->where('products.active', 1)
                ->whereNull('products.deleted_at')
                ->select('skus.id', 'skus.product_id', 'skus.sku')
                ->first();
            if (! $publicSku) {
                throw new RuntimeException('指定展示 SKU 不存在或已停用');
            }

            $realSkuExists = DB::connection($this->connection('real'))
                ->table('product_skus')
                ->where('id', $realSkuId)
                ->where('active', 1)
                ->exists();
            if (! $realSkuExists) {
                throw new RuntimeException('指定真实 SKU 不存在或已停用');
            }

            $main->table('catalog_sku_mappings')
                ->where('id', $mapping->id)
                ->update([
                    'public_sku_id'     => $publicSku->id,
                    'public_product_id' => $publicSku->product_id,
                    'public_sku'        => (string) $publicSku->sku,
                    'status'            => 'confirmed',
                    'match_type'        => 'manual',
                    'confidence'        => 1.0,
                    'candidate_data'    => null,
                    'updated_at'        => now(),
                ]);

            $this->refreshProductMappings($main, $version, (int) $mapping->real_product_id);

            return [
                'version'         => $version,
                'real_sku_id'     => $realSkuId,
                'public_sku_id'   => $publicSkuId,
                'status'          => 'confirmed',
            ];
        });
    }

    /**
     * 仅当版本中的 SKU 和商品映射全部确认后发布指定版本。
     */
    public function publishConfirmed(string $version): bool
    {
        $main = DB::connection($this->mainConnection());
        if (! $main->table('catalog_mapping_versions')->where('version', $version)->exists()) {
            throw new RuntimeException('映射版本不存在');
        }
        $hasMappings = $main->table('catalog_sku_mappings')->where('mapping_version', $version)->exists()
            && $main->table('catalog_product_mappings')->where('mapping_version', $version)->exists();
        if (! $hasMappings) {
            throw new RuntimeException('当前映射版本没有可发布的商品和 SKU 映射');
        }
        $pending = $main->table('catalog_sku_mappings')->where('mapping_version', $version)->whereIn('status', ['pending', 'conflict'])->exists()
            || $main->table('catalog_product_mappings')->where('mapping_version', $version)->whereIn('status', ['pending', 'conflict'])->exists();
        if ($pending) {
            return false;
        }

        $this->publish($main, $version);

        return true;
    }

    /**
     * 读取真实库和展示库 SKU，生成 SKU 映射与由 SKU 汇总出的商品映射。
     */
    private function buildMappings($main, string $mode, string $version): array
    {
        if ($mode === 'random') {
            return $this->buildRandomMappings($main, $version);
        }

        return $this->buildMatchedMappings($main, $mode, $version);
    }

    /**
     * 按真实商品分批生成精确或候选映射，避免大目录同时驻留所有 SKU 和映射行。
     */
    private function buildMatchedMappings($main, string $mode, string $version): array
    {
        $counts        = ['confirmed' => 0, 'pending' => 0, 'conflict' => 0];
        $productCounts = ['confirmed' => 0, 'pending' => 0, 'conflict' => 0];
        $skuCount      = 0;
        $productCount  = 0;
        $now           = now();

        $this->activeProductsQuery($this->connection('real'))
            ->select('id')
            ->orderBy('id')
            ->chunkById(self::PRODUCT_BATCH_SIZE, function ($products) use ($main, $mode, $version, $now, &$counts, &$productCounts, &$skuCount, &$productCount): void {
                $productIds        = $products->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $realSkusByProduct = $this->groupSkusByProduct($this->skuRowsForProductIds($this->connection('real'), $productIds));
                $realSkus          = array_merge(...array_values($realSkusByProduct ?: [[]]));
                if ($realSkus === []) {
                    return;
                }

                $publicSkus = $this->matchingPublicSkus($realSkus);
                $names      = $mode === 'candidate' ? $this->candidateNamesForProducts($productIds) : ['real' => [], 'public' => []];
                if ($names['public'] !== []) {
                    $publicSkus = $this->mergeSkuRows($publicSkus, $this->candidatePublicSkus($names));
                }
                $indexes                 = $this->indexPublicSkus($publicSkus);
                $publicSkusByProduct     = $this->groupSkusByProduct($publicSkus);
                $skuMappings             = [];
                $productMappings         = [];

                foreach ($productIds as $realProductId) {
                    $targets = [];
                    foreach ($realSkusByProduct[$realProductId] ?? [] as $realSku) {
                        $candidates    = $this->findExactCandidates($realSku, $indexes);
                        $status        = 'pending';
                        $matchType     = 'none';
                        $confidence    = 0.0;
                        $publicSku     = null;
                        $candidateData = [];

                        if (count($candidates) === 1) {
                            $status     = 'confirmed';
                            $matchType  = $candidates[0]['match_type'];
                            $confidence = 1.0;
                            $publicSku  = $candidates[0]['sku'];
                        } elseif (count($candidates) > 1) {
                            $status        = 'conflict';
                            $matchType     = 'multiple_exact';
                            $candidateData = ['public_sku_ids' => array_column($candidates, 'sku_id')];
                            if (count($candidates) >= self::EXACT_CANDIDATE_LIMIT) {
                                $candidateData['truncated'] = true;
                            }
                        } elseif ($mode === 'candidate') {
                            $candidateData = $this->nameCandidatesForProduct($realProductId, $names, $publicSkusByProduct);
                            if ($candidateData !== []) {
                                $matchType  = 'name_candidate';
                                $confidence = 0.5;
                            }
                        }

                        $publicProductId = $publicSku?->product_id;
                        $skuMappings[]   = [
                            'mapping_version'   => $version,
                            'real_sku_id'       => (int) $realSku->id,
                            'real_product_id'   => $realProductId,
                            'public_sku_id'     => $publicSku?->id,
                            'public_product_id' => $publicProductId,
                            'real_sku'          => (string) ($realSku->sku ?? ''),
                            'public_sku'        => (string) ($publicSku?->sku ?? ''),
                            'status'            => $status,
                            'match_type'        => $matchType,
                            'confidence'        => $confidence,
                            'candidate_data'    => $candidateData === [] ? null : json_encode($candidateData, JSON_UNESCAPED_UNICODE),
                            'created_at'        => $now,
                            'updated_at'        => $now,
                        ];
                        $targets[] = ['status' => $status, 'public_id' => $publicProductId, 'candidate' => $candidateData];
                        $counts[$status]++;
                        $skuCount++;
                    }

                    if ($targets === []) {
                        continue;
                    }
                    $mapping           = $this->productMappingRow($version, $realProductId, $targets, $now);
                    $productMappings[] = $mapping;
                    $productCounts[$mapping['status']]++;
                    $productCount++;
                }

                $this->insertMappings($main, 'catalog_sku_mappings', $skuMappings);
                $this->insertMappings($main, 'catalog_product_mappings', $productMappings);
            });

        $main->table('catalog_mapping_versions')->where('version', $version)->update([
            'product_count'  => $productCount,
            'sku_count'      => $skuCount,
            'conflict_count' => $counts['conflict'] + $productCounts['conflict'],
            'pending_count'  => $counts['pending']  + $productCounts['pending'],
            'updated_at'     => now(),
        ]);

        return [
            'version'   => $version,
            'products'  => $productCount,
            'skus'      => $skuCount,
            'confirmed' => $counts['confirmed'],
            'pending'   => $counts['pending']  + $productCounts['pending'],
            'conflict'  => $counts['conflict'] + $productCounts['conflict'],
            'published' => false,
        ];
    }

    /**
     * 按真实商品分批建立随机展示映射，保留随机版本的稳定复用语义。
     */
    private function buildRandomMappings($main, string $version): array
    {
        if (! $this->activeProductsQuery($this->connection('public'))->exists()) {
            throw new RuntimeException('随机映射没有可用的 Cloak 商品');
        }

        $previousVersion = $this->latestRandomVersion($main, $version);
        $eligibleCache   = [];
        $skuCount        = 0;
        $productCount    = 0;
        $now             = now();

        $this->activeProductsQuery($this->connection('real'))
            ->select('id')
            ->orderBy('id')
            ->chunkById(self::PRODUCT_BATCH_SIZE, function ($products) use ($main, $version, $previousVersion, $now, &$eligibleCache, &$skuCount, &$productCount): void {
                $productIds        = $products->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $realSkusByProduct = $this->groupSkusByProduct($this->skuRowsForProductIds($this->connection('real'), $productIds));
                $realSkuIds        = array_map(static fn (object $sku): int => (int) $sku->id, array_merge(...array_values($realSkusByProduct ?: [[]])));
                $previous          = $this->previousRandomMappingsForProducts($main, $previousVersion, $productIds, $realSkuIds);
                $plans             = [];
                $targetIds         = [];

                foreach ($productIds as $realProductId) {
                    $realSkus              = $realSkusByProduct[$realProductId] ?? [];
                    $targetProduct         = (int) ($previous['products'][$realProductId] ?? 0);
                    $plans[$realProductId] = [
                        'real_skus' => $realSkus,
                        'target_id' => $targetProduct,
                        'reused'    => $targetProduct > 0,
                    ];
                    if ($targetProduct > 0) {
                        $targetIds[] = $targetProduct;
                    }
                }

                $targets = $this->randomPublicTargets($targetIds);
                foreach ($plans as $realProductId => &$plan) {
                    $targetProductId = (int) $plan['target_id'];
                    $needsSku        = $plan['real_skus'] !== [];
                    if ($targetProductId > 0
                        && isset($targets['products'][$targetProductId])
                        && (! $needsSku || ($targets['skus'][$targetProductId] ?? []) !== [])) {
                        continue;
                    }

                    $plan['target_id'] = $this->chooseRandomPublicProductId(
                        $version,
                        $realProductId,
                        count($plan['real_skus']),
                        $eligibleCache
                    );
                    $plan['reused'] = false;
                    $targetIds[]    = $plan['target_id'];
                }
                unset($plan);
                $targets = $this->randomPublicTargets($targetIds);

                $skuMappings     = [];
                $productMappings = [];
                foreach ($plans as $realProductId => $plan) {
                    $targetProductId = (int) $plan['target_id'];
                    $targetSkus      = $targets['skus'][$targetProductId] ?? [];
                    if ($plan['real_skus'] !== [] && $targetSkus === []) {
                        throw new RuntimeException("展示商品 {$targetProductId} 没有可用 SKU，无法建立随机 SKU 映射");
                    }
                    $targetSkuById = [];
                    foreach ($targetSkus as $targetSku) {
                        $targetSkuById[(int) $targetSku->id] = $targetSku;
                    }

                    foreach ($plan['real_skus'] as $skuIndex => $realSku) {
                        $previousSku  = $previous['skus'][(int) $realSku->id] ?? null;
                        $publicSku    = null;
                        $skuMatchType = 'random_stable';
                        if ($previousSku
                            && (int) $previousSku->public_product_id === $targetProductId
                            && isset($targetSkuById[(int) $previousSku->public_sku_id])) {
                            $publicSku    = $targetSkuById[(int) $previousSku->public_sku_id];
                            $skuMatchType = 'random_persisted';
                        } else {
                            $publicSku = $targetSkus[$skuIndex % count($targetSkus)];
                        }
                        $skuMappings[] = [
                            'mapping_version'   => $version,
                            'real_sku_id'       => (int) $realSku->id,
                            'real_product_id'   => $realProductId,
                            'public_sku_id'     => (int) $publicSku->id,
                            'public_product_id' => $targetProductId,
                            'real_sku'          => (string) ($realSku->sku ?? ''),
                            'public_sku'        => (string) ($publicSku->sku ?? ''),
                            'status'            => 'confirmed',
                            'match_type'        => $skuMatchType,
                            'confidence'        => 0.1,
                            'candidate_data'    => null,
                            'created_at'        => $now,
                            'updated_at'        => $now,
                        ];
                        $skuCount++;
                    }
                    $productMappings[] = [
                        'mapping_version'   => $version,
                        'real_product_id'   => $realProductId,
                        'public_product_id' => $targetProductId,
                        'status'            => 'confirmed',
                        'match_type'        => $plan['reused'] ? 'random_persisted' : 'random_stable',
                        'confidence'        => 0.1,
                        'candidate_data'    => null,
                        'created_at'        => $now,
                        'updated_at'        => $now,
                    ];
                    $productCount++;
                }
                $this->insertMappings($main, 'catalog_sku_mappings', $skuMappings);
                $this->insertMappings($main, 'catalog_product_mappings', $productMappings);
            });

        $main->table('catalog_mapping_versions')->where('version', $version)->update([
            'product_count'  => $productCount,
            'sku_count'      => $skuCount,
            'conflict_count' => 0,
            'pending_count'  => 0,
            'updated_at'     => now(),
        ]);

        return [
            'version'   => $version,
            'products'  => $productCount,
            'skus'      => $skuCount,
            'confirmed' => $skuCount,
            'pending'   => 0,
            'conflict'  => 0,
            'published' => false,
        ];
    }

    /**
     * 读取已启用商品，按主键分页以控制单批内存。
     */
    private function activeProductsQuery(string $connection)
    {
        return DB::connection($connection)->table('products')
            ->where('active', 1)
            ->whereNull('deleted_at');
    }

    /**
     * 分批读取指定商品下启用 SKU，不在 PHP 中保留整库记录。
     *
     * @param array<int,int> $productIds
     * @return array<int,object>
     */
    private function skuRowsForProductIds(string $connection, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::connection($connection)->table('product_skus as skus')
            ->join('products', 'products.id', '=', 'skus.product_id')
            ->whereIn('skus.product_id', $productIds)
            ->where('products.active', 1)
            ->where('skus.active', 1)
            ->whereNull('products.deleted_at')
            ->select('skus.id', 'skus.product_id', 'skus.sku', 'skus.model', 'skus.variants')
            ->orderBy('skus.product_id')
            ->orderBy('skus.id')
            ->get()
            ->all();
    }

    /**
     * 仅查询与当前真实 SKU 批次可能匹配的展示 SKU，避免建立整库索引。
     * 同一匹配键最多保留有限候选；达到上限时仍可确定为冲突，管理员可继续用 SKU 搜索确认。
     *
     * @param array<int,object> $realSkus
     * @return array<int,object>
     */
    private function matchingPublicSkus(array $realSkus): array
    {
        $skus         = [];
        $models       = [];
        $requiredKeys = [];
        foreach ($realSkus as $realSku) {
            $sku = trim((string) ($realSku->sku ?? ''));
            if ($sku !== '') {
                $skus[] = $sku;
            }
            $model = trim((string) ($realSku->model ?? ''));
            if ($model !== '') {
                $models[] = $model;
            }
            foreach ($this->matchKeys($realSku) as $key) {
                $requiredKeys[$key] = true;
            }
        }
        $skus   = array_values(array_unique($skus));
        $models = array_values(array_unique($models));
        if ($skus === [] && $models === []) {
            return [];
        }

        $query = DB::connection($this->connection('public'))->table('product_skus as skus')
            ->join('products', 'products.id', '=', 'skus.product_id')
            ->where('products.active', 1)
            ->where('skus.active', 1)
            ->whereNull('products.deleted_at')
            ->where(function ($query) use ($skus, $models): void {
                if ($skus !== []) {
                    $query->whereIn('skus.sku', $skus);
                }
                if ($models !== []) {
                    $method = $skus === [] ? 'whereIn' : 'orWhereIn';
                    $query->{$method}('skus.model', $models);
                }
            })
            ->select('skus.id', 'skus.product_id', 'skus.sku', 'skus.model', 'skus.variants');

        $rows         = [];
        $matchesByKey = [];
        foreach ($query->lazyById(500, 'skus.id', 'id') as $row) {
            $matchingKeys = [];
            foreach ($this->matchKeys($row) as $key) {
                if (! isset($requiredKeys[$key]) || ($matchesByKey[$key] ?? 0) >= self::EXACT_CANDIDATE_LIMIT) {
                    continue;
                }
                $matchingKeys[] = $key;
            }
            if ($matchingKeys === []) {
                continue;
            }

            $rows[(int) $row->id] = $row;
            foreach (array_unique($matchingKeys) as $key) {
                $matchesByKey[$key] = ($matchesByKey[$key] ?? 0) + 1;
            }
        }

        return array_values($rows);
    }

    /**
     * 候选模式只读取当前真实商品的名称和同名展示商品，不扫描整张描述表。
     *
     * @param array<int,int> $realProductIds
     * @return array{real:array<int,string>,public:array<string,array<int,int>>}
     */
    private function candidateNamesForProducts(array $realProductIds): array
    {
        if ($realProductIds === []) {
            return ['real' => [], 'public' => []];
        }

        $realNames = [];
        $rawNames  = [];
        foreach (DB::connection($this->connection('real'))->table('product_descriptions')
            ->whereIn('product_id', $realProductIds)
            ->orderBy('id')
            ->get(['product_id', 'name']) as $row) {
            $rawName    = trim((string) $row->name);
            $normalName = $this->normalizeText($rawName);
            if ($normalName === '') {
                continue;
            }
            $realNames[(int) $row->product_id]  = $normalName;
            $rawNames[$rawName]                 = $normalName;
        }
        if ($rawNames === []) {
            return ['real' => $realNames, 'public' => []];
        }

        $publicNames = [];
        foreach ($rawNames as $rawName => $normalName) {
            $remaining = self::CANDIDATE_PRODUCT_LIMIT - count($publicNames[$normalName] ?? []);
            if ($remaining < 1) {
                continue;
            }

            $rows = DB::connection($this->connection('public'))->table('product_descriptions as descriptions')
                ->join('products', 'products.id', '=', 'descriptions.product_id')
                ->where('descriptions.name', $rawName)
                ->where('products.active', 1)
                ->whereNull('products.deleted_at')
                ->distinct()
                ->orderBy('descriptions.product_id')
                ->limit($remaining)
                ->get(['descriptions.product_id']);
            foreach ($rows as $row) {
                $publicNames[$normalName][(int) $row->product_id] = (int) $row->product_id;
            }
        }

        foreach ($publicNames as $name => $productIds) {
            $publicNames[$name] = array_values($productIds);
        }

        return ['real' => $realNames, 'public' => $publicNames];
    }

    /**
     * 每个真实名称最多读取有限展示 SKU，防止同名商品的 SKU 总数突破候选面板上限。
     *
     * @param array{real:array<int,string>,public:array<string,array<int,int>>} $names
     * @return array<int,object>
     */
    private function candidatePublicSkus(array $names): array
    {
        $rows = [];
        foreach ($names['public'] as $productIds) {
            if ($productIds === []) {
                continue;
            }

            $candidates = DB::connection($this->connection('public'))->table('product_skus as skus')
                ->join('products', 'products.id', '=', 'skus.product_id')
                ->whereIn('skus.product_id', $productIds)
                ->where('products.active', 1)
                ->where('skus.active', 1)
                ->whereNull('products.deleted_at')
                ->select('skus.id', 'skus.product_id', 'skus.sku', 'skus.model', 'skus.variants')
                ->orderBy('skus.product_id')
                ->orderBy('skus.id')
                ->limit(self::CANDIDATE_SKU_LIMIT)
                ->get();
            foreach ($candidates as $candidate) {
                $rows[(int) $candidate->id] = $candidate;
            }
        }

        return array_values($rows);
    }

    /**
     * 基于当前批次名称索引返回展示 SKU 候选，候选结果仍只供人工确认。
     *
     * @param array{real:array<int,string>,public:array<string,array<int,int>>} $names
     * @param array<int,array<int,object>>                                      $publicSkusByProduct
     */
    private function nameCandidatesForProduct(int $realProductId, array $names, array $publicSkusByProduct): array
    {
        $name = $names['real'][$realProductId] ?? '';
        if ($name === '') {
            return [];
        }

        $skuIds = [];
        foreach ($names['public'][$name] ?? [] as $publicProductId) {
            foreach ($publicSkusByProduct[(int) $publicProductId] ?? [] as $publicSku) {
                $skuIds[] = (int) $publicSku->id;
                if (count($skuIds) >= self::CANDIDATE_SKU_LIMIT) {
                    return [
                        'public_sku_ids' => $skuIds,
                        'reason'         => 'name_exact_candidate',
                        'truncated'      => true,
                    ];
                }
            }
        }
        $skuIds = array_values(array_unique($skuIds));

        return $skuIds === [] ? [] : ['public_sku_ids' => $skuIds, 'reason' => 'name_exact_candidate'];
    }

    /**
     * 合并两个小批次 SKU 结果并以 SKU ID 去重。
     *
     * @param array<int,object> $left
     * @param array<int,object> $right
     * @return array<int,object>
     */
    private function mergeSkuRows(array $left, array $right): array
    {
        $rows = [];
        foreach (array_merge($left, $right) as $row) {
            $rows[(int) $row->id] = $row;
        }

        return array_values($rows);
    }

    /**
     * 将一个真实商品的 SKU 结果汇总为商品映射行。
     *
     * @param array<int,array{status:string,public_id:mixed,candidate:array<string,mixed>}> $targets
     */
    private function productMappingRow(string $version, int $realProductId, array $targets, mixed $now): array
    {
        $publicIds   = array_values(array_unique(array_filter(array_column($targets, 'public_id'))));
        $hasConflict = in_array('conflict', array_column($targets, 'status'), true);
        $hasPending  = in_array('pending', array_column($targets, 'status'), true);
        $status      = $hasConflict || count($publicIds) > 1 ? 'conflict' : ($hasPending || $publicIds === [] ? 'pending' : 'confirmed');

        return [
            'mapping_version'   => $version,
            'real_product_id'   => $realProductId,
            'public_product_id' => count($publicIds) === 1 ? $publicIds[0] : null,
            'status'            => $status,
            'match_type'        => $status === 'confirmed' ? 'sku_exact' : 'derived_from_sku',
            'confidence'        => $status === 'confirmed' ? 1.0 : 0.0,
            'candidate_data'    => $status === 'confirmed' ? null : json_encode($targets, JSON_UNESCAPED_UNICODE),
            'created_at'        => $now,
            'updated_at'        => $now,
        ];
    }

    /**
     * 每批最多写入 500 行，避免 SQL 参数和 PHP 数组继续膨胀。
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function insertMappings($main, string $table, array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            $main->table($table)->insert($chunk);
        }
    }

    /**
     * 按商品 ID 分组 SKU，保持查询结果中的 SKU ID 顺序。
     *
     * @param array<int,object> $skus
     * @return array<int,array<int,object>>
     */
    private function groupSkusByProduct(array $skus): array
    {
        $grouped = [];
        foreach ($skus as $sku) {
            $grouped[(int) $sku->product_id][] = $sku;
        }

        return $grouped;
    }

    /**
     * 获取最近随机版本标识，后续只按当前批次读取其映射行。
     */
    private function latestRandomVersion($main, string $currentVersion): ?string
    {
        return $main->table('catalog_mapping_versions')
            ->where('source', 'random')
            ->where('version', '<>', $currentVersion)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('version');
    }

    /**
     * 读取当前真实商品批次对应的历史随机映射，避免一次加载历史全表。
     *
     * @param array<int,int> $realProductIds
     * @param array<int,int> $realSkuIds
     * @return array{products:array<int,int>,skus:array<int,object>}
     */
    private function previousRandomMappingsForProducts($main, ?string $version, array $realProductIds, array $realSkuIds): array
    {
        if (! $version) {
            return ['products' => [], 'skus' => []];
        }

        $products = [];
        foreach ($main->table('catalog_product_mappings')
            ->where('mapping_version', $version)
            ->whereIn('real_product_id', $realProductIds)
            ->get(['real_product_id', 'public_product_id']) as $row) {
            if ($row->public_product_id !== null) {
                $products[(int) $row->real_product_id] = (int) $row->public_product_id;
            }
        }
        $skus = [];
        if ($realSkuIds !== []) {
            foreach ($main->table('catalog_sku_mappings')
                ->where('mapping_version', $version)
                ->whereIn('real_sku_id', $realSkuIds)
                ->get(['real_sku_id', 'public_sku_id', 'public_product_id']) as $row) {
                $skus[(int) $row->real_sku_id] = $row;
            }
        }

        return ['products' => $products, 'skus' => $skus];
    }

    /**
     * 获取当前批次涉及的可用展示商品与 SKU。
     *
     * @param array<int,int> $productIds
     * @return array{products:array<int,bool>,skus:array<int,array<int,object>>}
     */
    private function randomPublicTargets(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($productIds === []) {
            return ['products' => [], 'skus' => []];
        }

        $products = [];
        foreach ($this->activeProductsQuery($this->connection('public'))
            ->whereIn('id', $productIds)
            ->pluck('id') as $productId) {
            $products[(int) $productId] = true;
        }

        return [
            'products' => $products,
            'skus'     => $this->groupSkusByProduct($this->skuRowsForProductIds($this->connection('public'), $productIds)),
        ];
    }

    /**
     * 为没有可复用记录的真实商品稳定地选择一个展示商品。
     *
     * @param array<string,array{min_id:int,max_id:int,total:int}> $eligibleCache
     */
    private function chooseRandomPublicProductId(string $version, int $realProductId, int $realSkuCount, array &$eligibleCache): int
    {
        // 截取 48 位哈希值，保持整数运算在 64 位 PHP 上稳定。
        $seed = hexdec(substr(hash('sha256', "cyber-cloak:{$version}:{$realProductId}"), 0, 12));

        foreach (array_unique([$realSkuCount, $realSkuCount > 0 ? 1 : 0]) as $minimum) {
            $productId = $this->randomEligiblePublicProductId((int) $minimum, $seed, $eligibleCache);
            if ($productId !== null) {
                return $productId;
            }
        }

        throw new RuntimeException('随机映射没有可分配 SKU 的 Cloak 商品');
    }

    /**
     * 用聚合元数据和主键区间选择展示商品，避免把整库商品 ID 缓存在 PHP 内存中。
     *
     * @param array<string,array{min_id:int,max_id:int,total:int}> $eligibleCache
     */
    private function randomEligiblePublicProductId(int $minimum, int $seed, array &$eligibleCache): ?int
    {
        $key = 'minimum:' . $minimum;
        if (! isset($eligibleCache[$key])) {
            $metadata = $this->randomEligibleProductsQuery($minimum)
                ->selectRaw('MIN(products.id) as min_id, MAX(products.id) as max_id, COUNT(*) as total')
                ->first();
            $eligibleCache[$key] = [
                'min_id' => (int) ($metadata->min_id ?? 0),
                'max_id' => (int) ($metadata->max_id ?? 0),
                'total'  => (int) ($metadata->total ?? 0),
            ];
        }

        $metadata = $eligibleCache[$key];
        if ($metadata['total'] < 1) {
            return null;
        }

        $range   = max(1, $metadata['max_id'] - $metadata['min_id'] + 1);
        $startId = $metadata['min_id'] + ($seed % $range);
        $query   = $this->randomEligibleProductsQuery($minimum);
        $id      = (clone $query)
            ->where('products.id', '>=', $startId)
            ->orderBy('products.id')
            ->value('products.id');

        return $id === null
            ? (int) $query->orderBy('products.id')->value('products.id')
            : (int) $id;
    }

    /**
     * 返回满足随机映射 SKU 数量要求的展示商品查询；不物化全量 ID 列表。
     */
    private function randomEligibleProductsQuery(int $minimum)
    {
        $query = $this->activeProductsQuery($this->connection('public'));
        if ($minimum < 1) {
            return $query;
        }

        return $query->whereRaw(
            '(SELECT COUNT(*) FROM product_skus as random_skus WHERE random_skus.product_id = products.id AND random_skus.active = 1) >= ?',
            [$minimum]
        );
    }

    /**
     * 发布映射版本时先归档旧版本，确保读取方只看到一个 published 版本。
     */
    private function publish($main, string $version): void
    {
        $previousVersion = $main->table('catalog_mapping_versions')
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->value('version');

        try {
            // 先在展示库事务中准备索引，旧索引在提交前对前台仍然可见。
            $this->replacePublishedSkuIndex($main, $version);
            $main->transaction(function () use ($main, $version): void {
                $main->table('catalog_mapping_versions')->where('status', 'published')->update([
                    'status'     => 'archived',
                    'updated_at' => now(),
                ]);
                $main->table('catalog_mapping_versions')->where('version', $version)->update([
                    'status'       => 'published',
                    'published_at' => now(),
                    'updated_at'   => now(),
                ]);
            });
        } catch (\Throwable $exception) {
            // 跨库事务无法原子提交；主库发布失败时尽力恢复此前已发布的展示索引。
            if ($previousVersion) {
                try {
                    $this->replacePublishedSkuIndex($main, (string) $previousVersion);
                } catch (\Throwable) {
                    // 保留原始发布异常，恢复失败由后续同步命令修复。
                }
            } else {
                try {
                    // 首次发布没有可恢复的旧版本，不能让未发布版本继续出现在前台。
                    $this->clearPublishedSkuIndex();
                } catch (\Throwable) {
                    // 保留原始发布异常，残留索引可由后续发布或同步命令覆盖。
                }
            }

            throw $exception;
        }

        try {
            $this->pruneInactivePublishedSkuIndex();
        } catch (\Throwable) {
            // 清理旧索引不影响新版本已发布状态，后续同步时会再次清理。
        }
    }

    /**
     * 分批把指定版本的已确认展示 SKU 写入展示库，整个切换过程不向前台暴露半成品索引。
     */
    private function replacePublishedSkuIndex($main, string $version): void
    {
        $public = DB::connection($this->connection('public'));
        if (! Schema::connection($this->connection('public'))->hasTable(self::PUBLISHED_SKU_INDEX_TABLE)) {
            throw new RuntimeException('展示 SKU 发布索引尚未创建，请先执行 CyberCloak 插件迁移');
        }

        $public->transaction(function () use ($main, $public, $version): void {
            $public->table(self::PUBLISHED_SKU_INDEX_TABLE)->where('active', 1)->update([
                'active'     => 0,
                'updated_at' => now(),
            ]);

            $main->table('catalog_sku_mappings')
                ->where('mapping_version', $version)
                ->where('status', 'confirmed')
                ->whereNotNull('public_sku_id')
                ->orderBy('id')
                ->chunkById(self::PUBLISHED_SKU_INDEX_BATCH_SIZE, function ($mappings) use ($public, $version): void {
                    $now  = now();
                    $rows = [];
                    foreach ($mappings as $mapping) {
                        $publicSkuId = (int) $mapping->public_sku_id;
                        if ($publicSkuId < 1) {
                            continue;
                        }
                        $rows[$publicSkuId] = [
                            'public_sku_id'     => $publicSkuId,
                            'public_product_id' => (int) $mapping->public_product_id,
                            'mapping_version'   => $version,
                            'active'            => 1,
                            'created_at'        => $now,
                            'updated_at'        => $now,
                        ];
                    }
                    if ($rows !== []) {
                        $public->table(self::PUBLISHED_SKU_INDEX_TABLE)->upsert(
                            array_values($rows),
                            ['public_sku_id'],
                            ['public_product_id', 'mapping_version', 'active', 'updated_at']
                        );
                    }
                });
        });
    }

    /**
     * 新版本发布完成后移除旧索引，避免展示库积累历史版本记录。
     */
    private function pruneInactivePublishedSkuIndex(): void
    {
        DB::connection($this->connection('public'))
            ->table(self::PUBLISHED_SKU_INDEX_TABLE)
            ->where('active', 0)
            ->delete();
    }

    /**
     * 首次发布失败后撤销展示库临时索引，避免未发布映射被前台读取。
     */
    private function clearPublishedSkuIndex(): void
    {
        DB::connection($this->connection('public'))
            ->table(self::PUBLISHED_SKU_INDEX_TABLE)
            ->where('active', 1)
            ->delete();
    }

    /**
     * 返回展示库中当前启用的索引数量，仅在后台同步操作完成后使用。
     */
    private function publishedSkuIndexCount(): int
    {
        return DB::connection($this->connection('public'))
            ->table(self::PUBLISHED_SKU_INDEX_TABLE)
            ->where('active', 1)
            ->count();
    }

    /**
     * 仅重算刚确认的真实商品，避免人工确认一条 SKU 时读取整版映射。
     */
    private function refreshProductMappings($main, string $version, int $realProductId): void
    {
        $rows = $main->table('catalog_sku_mappings')
            ->where('mapping_version', $version)
            ->where('real_product_id', $realProductId)
            ->orderBy('real_sku_id')
            ->get(['status', 'public_product_id']);
        $publicIds   = array_values(array_unique(array_filter($rows->pluck('public_product_id')->map(fn ($id): int => (int) $id)->all())));
        $hasConflict = $rows->contains(fn ($row): bool => $row->status === 'conflict');
        $hasPending  = $rows->contains(fn ($row): bool => $row->status === 'pending');
        $status      = $hasConflict || count($publicIds) > 1 ? 'conflict' : ($hasPending || $publicIds === [] ? 'pending' : 'confirmed');

        $main->table('catalog_product_mappings')
            ->where('mapping_version', $version)
            ->where('real_product_id', $realProductId)
            ->update([
                'public_product_id' => count($publicIds) === 1 ? $publicIds[0] : null,
                'status'            => $status,
                'match_type'        => $status === 'confirmed' ? 'sku_exact' : 'derived_from_sku',
                'confidence'        => $status === 'confirmed' ? 1.0 : 0.0,
                'updated_at'        => now(),
            ]);

        $this->refreshVersionCounts($main, $version);
    }

    /**
     * 统计直接由数据库聚合，避免确认操作把整版 SKU 映射加载进 PHP。
     */
    private function refreshVersionCounts($main, string $version): void
    {
        $skuCounts     = $main->table('catalog_sku_mappings')->where('mapping_version', $version)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $productCounts = $main->table('catalog_product_mappings')->where('mapping_version', $version)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $main->table('catalog_mapping_versions')->where('version', $version)->update([
            'product_count'  => (int) $productCounts->sum(),
            'sku_count'      => (int) $skuCounts->sum(),
            'conflict_count' => (int) ($skuCounts['conflict'] ?? 0) + (int) ($productCounts['conflict'] ?? 0),
            'pending_count'  => (int) ($skuCounts['pending'] ?? 0)  + (int) ($productCounts['pending'] ?? 0),
            'updated_at'     => now(),
        ]);
    }

    /**
     * 为展示库 SKU 建立多索引，匹配优先级为 SKU+规格、SKU、型号+规格、型号。
     *
     * @param array<int,object> $rows
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function indexPublicSkus(array $rows): array
    {
        $index = [];
        foreach ($rows as $row) {
            foreach ($this->matchKeys($row) as $key) {
                if (count($index[$key] ?? []) >= self::EXACT_CANDIDATE_LIMIT) {
                    continue;
                }
                $index[$key][] = [
                    'id'         => (int) $row->id,
                    'sku_id'     => (int) $row->id,
                    'product_id' => (int) $row->product_id,
                    'sku'        => $row,
                    'match_type' => str_contains($key, 'sku:') ? 'sku_exact' : 'model_variant_exact',
                ];
            }
        }

        return $index;
    }

    /**
     * 使用精确字段查找候选，不使用模糊结果直接确认映射。
     *
     * @return array<int,array<string,mixed>>
     */
    private function findExactCandidates(object $row, array $index): array
    {
        foreach ($this->matchKeys($row) as $key) {
            if (empty($index[$key])) {
                continue;
            }
            $unique = [];
            foreach ($index[$key] as $candidate) {
                $unique[$candidate['sku_id']] = $candidate;
            }

            return array_values($unique);
        }

        return [];
    }

    /**
     * 生成标准化匹配键。
     *
     * @return array<int,string>
     */
    private function matchKeys(object $row): array
    {
        $sku      = $this->normalizeText($row->sku ?? '');
        $model    = $this->normalizeText($row->model ?? '');
        $variants = $this->variantSignature($row->variants ?? null);
        $keys     = [];
        if ($sku !== '' && $variants !== '') {
            $keys[] = "sku:{$sku}|variants:{$variants}";
        }
        if ($sku !== '') {
            $keys[] = "sku:{$sku}";
        }
        if ($model !== '' && $variants !== '') {
            $keys[] = "model:{$model}|variants:{$variants}";
        }
        if ($model !== '') {
            $keys[] = "model:{$model}";
        }

        return $keys;
    }

    /**
     * 标准化 SKU、型号和名称，统一大小写和空白字符。
     */
    private function normalizeText(mixed $value): string
    {
        $value = trim((string) $value);

        return function_exists('mb_strtolower') ? mb_strtolower((string) preg_replace('/\s+/u', ' ', $value)) : strtolower($value);
    }

    /**
     * 将规格 JSON 规范化后生成稳定签名，避免字段顺序影响精确匹配。
     */
    private function variantSignature(mixed $variants): string
    {
        if (is_string($variants)) {
            $variants = json_decode($variants, true);
        }
        if (! is_array($variants) || $variants === []) {
            return '';
        }
        $this->sortRecursive($variants);

        return hash('sha256', json_encode($variants, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 递归排序对象键，保证相同规格的 JSON 表达一致。
     */
    private function sortRecursive(array &$value): void
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursive($item);
            }
        }
        unset($item);
        if (! array_is_list($value)) {
            ksort($value);
        }
    }

    /**
     * 获取当前映射版本标识。
     */
    private function newVersion(): string
    {
        return 'v' . now()->format('YmdHis') . '-' . Str::lower(Str::random(6));
    }

    /**
     * 获取主库连接名。
     */
    private function mainConnection(): string
    {
        return (string) config('database.default', 'mysql');
    }

    /**
     * 获取商品库连接名，并限制模式只能是 real/public。
     */
    private function connection(string $mode): string
    {
        if (! in_array($mode, ['real', 'public'], true)) {
            throw new RuntimeException('商品库模式无效');
        }

        return (string) config("cyber_cloak.connections.{$mode}", $mode === 'real' ? 'mysql' : 'catalog_public');
    }
}
