<?php

namespace Plugin\CyberCloak\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class SkuMappingService
{
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
     * @return array{version:string,real_sku_id:int,public_sku_id:int,fulfillment_sku:string,status:string}
     */
    public function confirm(string $version, int $realSkuId, int $publicSkuId, string $fulfillmentSku = null): array
    {
        if ($version === '' || $realSkuId < 1 || $publicSkuId < 1) {
            throw new RuntimeException('确认映射必须提供有效的版本、真实 SKU 和展示 SKU');
        }

        $main = DB::connection($this->mainConnection());

        return $main->transaction(function () use ($main, $version, $realSkuId, $publicSkuId, $fulfillmentSku): array {
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

            $realSku = DB::connection($this->connection('real'))
                ->table('product_skus')
                ->where('id', $realSkuId)
                ->where('active', 1)
                ->first(['id', 'sku']);
            if (! $realSku) {
                throw new RuntimeException('指定真实 SKU 不存在或已停用');
            }

            $fulfillment = trim((string) ($fulfillmentSku ?: $realSku->sku));
            if ($fulfillment === '') {
                throw new RuntimeException('履约 SKU 不能为空');
            }

            $main->table('catalog_sku_mappings')
                ->where('id', $mapping->id)
                ->update([
                    'public_sku_id'     => $publicSku->id,
                    'public_product_id' => $publicSku->product_id,
                    'public_sku'        => (string) $publicSku->sku,
                    'fulfillment_sku'   => $fulfillment,
                    'status'            => 'confirmed',
                    'match_type'        => 'manual',
                    'confidence'        => 1.0,
                    'candidate_data'    => null,
                    'updated_at'        => now(),
                ]);

            $this->refreshProductMappings($main, $version);

            return [
                'version'         => $version,
                'real_sku_id'     => $realSkuId,
                'public_sku_id'   => $publicSkuId,
                'fulfillment_sku' => $fulfillment,
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

        $realSkus   = $this->skuRows($this->connection('real'));
        $publicSkus = $this->skuRows($this->connection('public'));
        $indexes    = $this->indexPublicSkus($publicSkus);
        $names      = $mode === 'candidate' ? $this->productNameIndex() : [];

        $skuMappings    = [];
        $productTargets = [];
        $counts         = ['confirmed' => 0, 'pending' => 0, 'conflict' => 0];
        foreach ($realSkus as $realSku) {
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
            } elseif ($mode === 'candidate') {
                $candidateData = $this->nameCandidates($realSku, $names, $publicSkus);
                if ($candidateData !== []) {
                    $matchType  = 'name_candidate';
                    $confidence = 0.5;
                }
            }

            $publicProductId = $publicSku?->product_id;
            $skuMappings[]   = [
                'mapping_version'   => $version,
                'real_sku_id'       => (int) $realSku->id,
                'real_product_id'   => (int) $realSku->product_id,
                'public_sku_id'     => $publicSku?->id,
                'public_product_id' => $publicProductId,
                'real_sku'          => (string) ($realSku->sku ?? ''),
                'public_sku'        => (string) ($publicSku?->sku ?? ''),
                'fulfillment_sku'   => (string) ($realSku->sku ?? ''),
                'status'            => $status,
                'match_type'        => $matchType,
                'confidence'        => $confidence,
                'candidate_data'    => $candidateData === [] ? null : json_encode($candidateData, JSON_UNESCAPED_UNICODE),
                'created_at'        => now(),
                'updated_at'        => now(),
            ];
            $productTargets[(int) $realSku->product_id][] = [
                'status'     => $status,
                'public_id'  => $publicProductId,
                'candidate'  => $candidateData,
            ];
            $counts[$status]++;
        }

        foreach (array_chunk($skuMappings, 500) as $chunk) {
            $main->table('catalog_sku_mappings')->insert($chunk);
        }

        $productMappings = [];
        foreach ($productTargets as $realProductId => $targets) {
            $publicIds         = array_values(array_unique(array_filter(array_column($targets, 'public_id'))));
            $hasConflict       = in_array('conflict', array_column($targets, 'status'), true);
            $hasPending        = in_array('pending', array_column($targets, 'status'), true);
            $status            = $hasConflict || count($publicIds) > 1 ? 'conflict' : ($hasPending || $publicIds === [] ? 'pending' : 'confirmed');
            $publicId          = count($publicIds) === 1 ? $publicIds[0] : null;
            $productMappings[] = [
                'mapping_version'   => $version,
                'real_product_id'   => $realProductId,
                'public_product_id' => $publicId,
                'status'            => $status,
                'match_type'        => $status === 'confirmed' ? 'sku_exact' : 'derived_from_sku',
                'confidence'        => $status === 'confirmed' ? 1.0 : 0.0,
                'candidate_data'    => $status === 'confirmed' ? null : json_encode($targets, JSON_UNESCAPED_UNICODE),
                'created_at'        => now(),
                'updated_at'        => now(),
            ];
        }
        foreach (array_chunk($productMappings, 500) as $chunk) {
            $main->table('catalog_product_mappings')->insert($chunk);
        }

        $productCounts = ['confirmed' => 0, 'pending' => 0, 'conflict' => 0];
        foreach ($productMappings as $mapping) {
            $productCounts[$mapping['status']]++;
        }
        $main->table('catalog_mapping_versions')->where('version', $version)->update([
            'product_count'  => count($productMappings),
            'sku_count'      => count($skuMappings),
            'conflict_count' => $counts['conflict'] + $productCounts['conflict'],
            'pending_count'  => $counts['pending']  + $productCounts['pending'],
            'updated_at'     => now(),
        ]);

        return [
            'version'   => $version,
            'products'  => count($productMappings),
            'skus'      => count($skuMappings),
            'confirmed' => $counts['confirmed'],
            'pending'   => $counts['pending']  + $productCounts['pending'],
            'conflict'  => $counts['conflict'] + $productCounts['conflict'],
            'published' => false,
        ];
    }

    /**
     * 按真实商品建立随机展示映射，并优先复用最近一次随机映射。
     *
     * 同一真实商品的多个 SKU 都落在同一个展示商品下；履约 SKU 始终保留真实库 SKU。
     */
    private function buildRandomMappings($main, string $version): array
    {
        $realProducts = DB::connection($this->connection('real'))
            ->table('products')
            ->where('active', 1)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $realSkus       = $this->skuRows($this->connection('real'));
        $publicSkus     = $this->skuRows($this->connection('public'));
        $publicProducts = DB::connection($this->connection('public'))
            ->table('products')
            ->where('active', 1)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($publicProducts === []) {
            throw new RuntimeException('随机映射没有可用的 Cloak 商品');
        }

        $realSkusByProduct   = $this->groupSkusByProduct($realSkus);
        $publicSkusByProduct = $this->groupSkusByProduct($publicSkus);
        $previous            = $this->previousRandomMappings($main, $version);
        $publicProductSet    = array_fill_keys($publicProducts, true);
        $skuMappings         = [];
        $productMappings     = [];
        $counts              = ['confirmed' => 0, 'pending' => 0, 'conflict' => 0];

        foreach ($realProducts as $realProductId) {
            $realProductSkus = $realSkusByProduct[$realProductId] ?? [];
            $targetProductId = (int) ($previous['products'][$realProductId] ?? 0);
            $productReused   = $targetProductId > 0
                && isset($publicProductSet[$targetProductId])
                && ($realProductSkus === [] || ($publicSkusByProduct[$targetProductId] ?? []) !== []);
            if (! $productReused) {
                // 新商品使用版本号参与的稳定伪随机选择，事务重试时仍得到同一个结果。
                $targetProductId = $this->chooseRandomPublicProduct(
                    $version,
                    $realProductId,
                    $publicProducts,
                    $publicSkusByProduct,
                    count($realProductSkus)
                );
            }

            $targetSkus = $publicSkusByProduct[$targetProductId] ?? [];
            if ($realProductSkus !== [] && $targetSkus === []) {
                throw new RuntimeException("展示商品 {$targetProductId} 没有可用 SKU，无法建立随机 SKU 映射");
            }
            $targetSkuById = [];
            foreach ($targetSkus as $targetSku) {
                $targetSkuById[(int) $targetSku->id] = $targetSku;
            }

            foreach ($realProductSkus as $skuIndex => $realSku) {
                $previousSku  = $previous['skus'][(int) $realSku->id] ?? null;
                $publicSku    = null;
                $skuMatchType = 'random_stable';
                if ($previousSku
                    && (int) $previousSku->public_product_id === $targetProductId
                    && isset($targetSkuById[(int) $previousSku->public_sku_id])) {
                    $publicSku    = $targetSkuById[(int) $previousSku->public_sku_id];
                    $skuMatchType = 'random_persisted';
                } else {
                    // SKU 数量不足时循环使用展示商品 SKU，但真实履约身份仍然唯一。
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
                    'fulfillment_sku'   => (string) ($realSku->sku ?? ''),
                    'status'            => 'confirmed',
                    'match_type'        => $skuMatchType,
                    'confidence'        => 0.1,
                    'candidate_data'    => null,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ];
                $counts['confirmed']++;
            }

            $productMappings[] = [
                'mapping_version'   => $version,
                'real_product_id'   => $realProductId,
                'public_product_id' => $targetProductId,
                'status'            => 'confirmed',
                'match_type'        => $productReused ? 'random_persisted' : 'random_stable',
                'confidence'        => 0.1,
                'candidate_data'    => null,
                'created_at'        => now(),
                'updated_at'        => now(),
            ];
        }

        foreach (array_chunk($skuMappings, 500) as $chunk) {
            $main->table('catalog_sku_mappings')->insert($chunk);
        }
        foreach (array_chunk($productMappings, 500) as $chunk) {
            $main->table('catalog_product_mappings')->insert($chunk);
        }

        $main->table('catalog_mapping_versions')->where('version', $version)->update([
            'product_count'  => count($productMappings),
            'sku_count'      => count($skuMappings),
            'conflict_count' => 0,
            'pending_count'  => 0,
            'updated_at'     => now(),
        ]);

        return [
            'version'   => $version,
            'products'  => count($productMappings),
            'skus'      => count($skuMappings),
            'confirmed' => $counts['confirmed'],
            'pending'   => 0,
            'conflict'  => 0,
            'published' => false,
        ];
    }

    /**
     * 按商品 ID 分组 SKU，保持数据库 ID 顺序以便重复构建时结果可复现。
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
        foreach ($grouped as &$rows) {
            usort($rows, fn (object $left, object $right): int => (int) $left->id <=> (int) $right->id);
        }
        unset($rows);

        return $grouped;
    }

    /**
     * 读取最近一次随机版本，确保新版本重建不会重新随机已有真实商品。
     *
     * @return array{products:array<int,int>,skus:array<int,object>}
     */
    private function previousRandomMappings($main, string $currentVersion): array
    {
        $version = $main->table('catalog_mapping_versions')
            ->where('source', 'random')
            ->where('version', '<>', $currentVersion)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('version');
        if (! $version) {
            return ['products' => [], 'skus' => []];
        }

        $products = [];
        foreach ($main->table('catalog_product_mappings')->where('mapping_version', $version)->get(['real_product_id', 'public_product_id']) as $row) {
            if ($row->public_product_id !== null) {
                $products[(int) $row->real_product_id] = (int) $row->public_product_id;
            }
        }
        $skus = [];
        foreach ($main->table('catalog_sku_mappings')->where('mapping_version', $version)->get(['real_sku_id', 'public_sku_id', 'public_product_id']) as $row) {
            $skus[(int) $row->real_sku_id] = $row;
        }

        return ['products' => $products, 'skus' => $skus];
    }

    /**
     * 为没有历史映射的真实商品选择稳定伪随机展示商品。
     *
     * 有 SKU 时优先选择 SKU 数量足够的展示商品，避免无意义的 SKU 复用。
     */
    private function chooseRandomPublicProduct(
        string $version,
        int $realProductId,
        array $publicProducts,
        array $publicSkusByProduct,
        int $realSkuCount
    ): int {
        $eligible = array_values(array_filter(
            $publicProducts,
            fn (int $publicProductId): bool => $realSkuCount === 0 || ($publicSkusByProduct[$publicProductId] ?? []) !== []
        ));
        if ($realSkuCount > 0) {
            $enough = array_values(array_filter(
                $eligible,
                fn (int $publicProductId): bool => count($publicSkusByProduct[$publicProductId] ?? []) >= $realSkuCount
            ));
            if ($enough !== []) {
                $eligible = $enough;
            }
        }
        if ($eligible === []) {
            throw new RuntimeException('随机映射没有可分配 SKU 的 Cloak 商品');
        }

        // 截取 48 位哈希值，保持整数运算在 64 位 PHP 上稳定。
        $seed = hexdec(substr(hash('sha256', "cyber-cloak:{$version}:{$realProductId}"), 0, 12));

        return (int) $eligible[$seed % count($eligible)];
    }

    /**
     * 发布映射版本时先归档旧版本，确保读取方只看到一个 published 版本。
     */
    private function publish($main, string $version): void
    {
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
    }

    /**
     * 根据 SKU 确认结果重算商品映射和版本待处理统计。
     */
    private function refreshProductMappings($main, string $version): void
    {
        $skuRows = $main->table('catalog_sku_mappings')
            ->where('mapping_version', $version)
            ->orderBy('real_sku_id')
            ->get();
        $grouped = [];
        foreach ($skuRows as $row) {
            $grouped[(int) $row->real_product_id][] = $row;
        }

        foreach ($grouped as $realProductId => $rows) {
            $publicIds   = array_values(array_unique(array_filter(array_map(fn ($row) => (int) $row->public_product_id, $rows))));
            $hasConflict = collect($rows)->contains(fn ($row) => $row->status === 'conflict');
            $hasPending  = collect($rows)->contains(fn ($row) => $row->status === 'pending');
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
        }

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
     * 读取启用的 SKU，并兼容真实库旧版本缺少 active 字段的情况由迁移保证。
     *
     * @return array<int,object>
     */
    private function skuRows(string $connection): array
    {
        return DB::connection($connection)->table('product_skus as skus')
            ->join('products', 'products.id', '=', 'skus.product_id')
            ->where('products.active', 1)
            ->where('skus.active', 1)
            ->whereNull('products.deleted_at')
            ->select('skus.id', 'skus.product_id', 'skus.sku', 'skus.model', 'skus.variants')
            ->orderBy('skus.id')
            ->get()
            ->all();
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
     * 候选模式按商品名称生成待人工确认候选，永远不返回 confirmed。
     */
    private function nameCandidates(object $realSku, array $names, array $publicSkus): array
    {
        $productName = $names[(int) $realSku->product_id] ?? '';
        if ($productName === '') {
            return [];
        }
        $publicProductIds = $names['__public__'][$productName] ?? [];
        $skuIds           = [];
        foreach ($publicSkus as $publicSku) {
            if (in_array((int) $publicSku->product_id, $publicProductIds, true)) {
                $skuIds[] = (int) $publicSku->id;
            }
        }

        return $skuIds === [] ? [] : ['public_sku_ids' => $skuIds, 'reason' => 'name_exact_candidate'];
    }

    /**
     * 建立真实商品名和展示商品名索引，仅用于候选提示。
     */
    private function productNameIndex(): array
    {
        $index = [];
        foreach (['real', 'public'] as $mode) {
            $connection = $this->connection($mode);
            $rows       = DB::connection($connection)->table('product_descriptions')->select('product_id', 'name')->get();
            foreach ($rows as $row) {
                $name = $this->normalizeText($row->name);
                if ($name === '') {
                    continue;
                }
                if ($mode === 'real') {
                    $index[(int) $row->product_id] = $name;
                } else {
                    $index['__public__'][$name][] = (int) $row->product_id;
                }
            }
        }

        return $index;
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
