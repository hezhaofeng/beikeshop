<?php

namespace Plugin\CyberCloak\Controllers;

use Beike\Admin\Http\Controllers\Controller;
use Beike\Models\Order;
use Beike\Repositories\SettingRepo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Plugin\CyberCloak\Services\AccessKeyService;
use Plugin\CyberCloak\Services\CatalogRouteService;
use Plugin\CyberCloak\Services\HomeDesignMappingService;
use Plugin\CyberCloak\Services\IpProviderSyncService;
use Plugin\CyberCloak\Services\SkuMappingService;

class AdminCyberCloakController extends Controller
{
    /**
     * 返回映射版本、待确认 SKU 和当前路由统计，供插件编辑页展示。
     */
    public function mappingStatus(Request $request, SkuMappingService $mappings): mixed
    {
        try {
            return json_success('映射状态已更新', $mappings->dashboard($request->input('version')));
        } catch (\Throwable $exception) {
            return json_fail('读取映射状态失败：' . $exception->getMessage());
        }
    }

    /**
     * 搜索展示库中的可用 SKU，供映射异常行直接选择。
     */
    public function searchPublicSkus(Request $request, SkuMappingService $mappings): mixed
    {
        $data = $request->validate([
            'keyword' => 'nullable|string|max:128',
            'limit'   => 'nullable|integer|min:1|max:50',
        ]);

        try {
            return json_success('展示 SKU 查询完成', $mappings->searchPublicSkus(
                (string) ($data['keyword'] ?? ''),
                (int) ($data['limit'] ?? 20)
            ));
        } catch (\Throwable $exception) {
            return json_fail('展示 SKU 查询失败：' . $exception->getMessage());
        }
    }

    /**
     * 从后台生成一个新的商品映射版本，默认使用精确匹配。
     */
    public function rebuildMappings(Request $request, SkuMappingService $mappings): mixed
    {
        $data = $request->validate([
            'mode'  => 'required|in:exact,candidate,random',
            'force' => 'nullable|boolean',
        ]);

        try {
            $result = $mappings->rebuild(
                (string) $data['mode'],
                null,
                true,
                (bool) ($data['force'] ?? false)
            );
            Log::info('CyberCloak 后台生成商品映射', [
                'version'  => $result['version'],
                'mode'     => $data['mode'],
                'admin_id' => auth()->id(),
            ]);

            return json_success('商品映射已生成', $result);
        } catch (\Throwable $exception) {
            return json_fail('商品映射生成失败：' . $exception->getMessage());
        }
    }

    /**
     * 一键重建商品 SKU 映射，并在成功发布后只更新商品稳定链接。
     *
     * 分类链接和首页 Banner 检查由各自按钮负责，避免一次操作覆盖不相关的路由数据。
     */
    public function rebuildProductMappings(Request $request, SkuMappingService $mappings, CatalogRouteService $routes): mixed
    {
        $data = $request->validate([
            'mode'    => 'nullable|in:exact,candidate,random',
            'force'   => 'nullable|boolean',
            'version' => 'nullable|string|max:64',
        ]);

        try {
            if (! empty($data['version'])) {
                if (! $mappings->publishConfirmed((string) $data['version'])) {
                    return json_fail('当前商品映射仍有待确认或冲突项', ['version' => $data['version']]);
                }
                $result = [
                    'version'   => (string) $data['version'],
                    'published' => true,
                    'confirmed' => 0,
                    'pending'   => 0,
                    'conflict'  => 0,
                ];
            } else {
                $result = $mappings->rebuild(
                    (string) ($data['mode'] ?? 'exact'),
                    null,
                    true,
                    (bool) ($data['force'] ?? false)
                );
            }
            $result['product_routes'] = $result['published']
                ? $routes->rebuild('product', $result['version'])
                : null;
            Log::info('CyberCloak 后台执行商品 SKU 映射', [
                'version'   => $result['version'],
                'mode'      => $data['mode'] ?? 'exact',
                'published' => $result['published'],
                'admin_id'  => auth()->id(),
            ]);

            return json_success('商品 SKU 与商品映射已处理', $result);
        } catch (\Throwable $exception) {
            return json_fail('商品 SKU 映射失败：' . $exception->getMessage());
        }
    }

    /**
     * 使用当前已发布版本重建分类稳定链接。
     */
    public function rebuildCategoryMappings(Request $request, CatalogRouteService $routes): mixed
    {
        $version = $request->input('version');
        if ($version !== null) {
            $request->validate(['version' => 'string|max:64']);
        }

        try {
            $result = $routes->rebuild('category', $version ? (string) $version : null);
            Log::info('CyberCloak 后台执行分类映射', [
                'version'  => $result['version'],
                'routes'   => $result['routes'],
                'unmapped' => $result['unmapped'],
                'admin_id' => auth()->id(),
            ]);

            return json_success('分类映射已完成', $result);
        } catch (\Throwable $exception) {
            return json_fail('分类映射失败：' . $exception->getMessage());
        }
    }

    /**
     * 扫描首页装修的 Banner 链接，不创建第二套装修配置。
     */
    public function scanBannerMappings(HomeDesignMappingService $designMappings): mixed
    {
        try {
            $result = $designMappings->scan();
            Log::info('CyberCloak 后台扫描首页 Banner 映射', [
                'banner_count' => $result['banner_count'],
                'mapped'       => $result['mapped'],
                'unmapped'     => $result['unmapped'],
                'admin_id'     => auth()->id(),
            ]);

            return json_success('首页 Banner 映射检查完成', $result);
        } catch (\Throwable $exception) {
            return json_fail('首页 Banner 映射检查失败：' . $exception->getMessage());
        }
    }

    /**
     * 清理设置、配置、视图和应用缓存，让首页装修及映射结果立即生效。
     */
    public function clearMappingCache(): mixed
    {
        try {
            SettingRepo::clearCache();
            Log::info('CyberCloak 后台清除映射缓存', ['admin_id' => auth()->id()]);

            return json_success('映射及首页装修缓存已清除');
        } catch (\Throwable $exception) {
            return json_fail('清除映射缓存失败：' . $exception->getMessage());
        }
    }

    /**
     * 保存后台选中的真实 SKU 与展示 SKU 对应关系。
     */
    public function confirmMapping(Request $request, SkuMappingService $mappings): mixed
    {
        $data = $request->validate([
            'version'         => 'required|string|max:64',
            'real_sku_id'     => 'required|integer|min:1',
            'public_sku_id'   => 'required|integer|min:1',
            'fulfillment_sku' => 'nullable|string|max:128',
        ]);

        try {
            $result = $mappings->confirm(
                (string) $data['version'],
                (int) $data['real_sku_id'],
                (int) $data['public_sku_id'],
                isset($data['fulfillment_sku']) ? (string) $data['fulfillment_sku'] : null
            );
            Log::info('CyberCloak 后台确认 SKU 映射', [
                'version'       => $result['version'],
                'real_sku_id'   => $result['real_sku_id'],
                'public_sku_id' => $result['public_sku_id'],
                'admin_id'      => auth()->id(),
            ]);

            return json_success('SKU 映射已确认', $result);
        } catch (\Throwable $exception) {
            return json_fail('SKU 映射确认失败：' . $exception->getMessage());
        }
    }

    /**
     * 发布没有待确认项的映射版本。
     */
    public function publishMapping(Request $request, SkuMappingService $mappings): mixed
    {
        $version = (string) $request->validate(['version' => 'required|string|max:64'])['version'];

        try {
            if (! $mappings->publishConfirmed($version)) {
                return json_fail('当前版本仍有待确认或冲突映射', ['version' => $version]);
            }
            Log::info('CyberCloak 后台发布商品映射', [
                'version'  => $version,
                'admin_id' => auth()->id(),
            ]);

            return json_success('商品映射已发布', ['version' => $version]);
        } catch (\Throwable $exception) {
            return json_fail('商品映射发布失败：' . $exception->getMessage());
        }
    }

    /**
     * 使用当前已发布版本一次重建商品和分类稳定 URL。
     */
    public function rebuildRoutes(Request $request, CatalogRouteService $routes): mixed
    {
        $version = $request->input('version');
        if ($version !== null) {
            $request->validate(['version' => 'string|max:64']);
        }

        try {
            $result = $routes->rebuildAll($version ? (string) $version : null);
            Log::info('CyberCloak 后台重建商品和分类路由', [
                'version'  => $result['version'],
                'admin_id' => auth()->id(),
            ]);

            return json_success('商品和分类链接已同步', $result);
        } catch (\Throwable $exception) {
            return json_fail('链接同步失败：' . $exception->getMessage());
        }
    }

    /**
     * 返回脱敏后的 key 列表，后台页面不应再次暴露历史明文 key。
     */
    public function keys(AccessKeyService $keys): array
    {
        $items = array_map(static function (array $record): array {
            return [
                'id'         => $record['id'],
                'status'     => $record['status'],
                'expires_at' => $record['expires_at'],
                'hash'       => $record['hash'] ? substr((string) $record['hash'], 0, 8) . '...' : null,
            ];
        }, $keys->all());

        return ['items' => $items];
    }

    /**
     * 使用管理员提交的明文 key 创建摘要记录，响应只返回一次明文 key。
     */
    public function createKey(Request $request, AccessKeyService $keys): array
    {
        $data = $request->validate([
            'key'        => 'required|string|max:512',
            'expires_at' => 'nullable|date',
        ]);
        $record = $keys->create($data['key'], isset($data['expires_at']) ? new \DateTimeImmutable($data['expires_at']) : null);

        return [
            'id'         => $record['id'],
            'key'        => $data['key'],
            'expires_at' => $record['expires_at'],
        ];
    }

    /**
     * 更新 key 的启用状态。
     */
    public function updateKey(string $id, Request $request, AccessKeyService $keys): mixed
    {
        $status  = $request->validate(['status' => 'required|in:enabled,disabled'])['status'];
        $updated = $status === 'enabled' ? $keys->enable($id) : $keys->disable($id);
        if (! $updated) {
            return json_fail('访问 key 不存在');
        }

        return json_success('访问 key 状态已更新');
    }

    /**
     * 删除指定访问 key。
     */
    public function deleteKey(string $id, AccessKeyService $keys): mixed
    {
        return $keys->remove($id) ? json_success('访问 key 已删除') : json_fail('访问 key 不存在');
    }

    /**
     * 测试供应商返回值并输出标准化统计，不写本地缓存。
     */
    public function testProvider(Request $request, IpProviderSyncService $sync): array
    {
        return $sync->test($this->providerOverrides($request));
    }

    /**
     * 从后台触发一次供应商同步，支持试运行模式。
     */
    public function syncProvider(Request $request, IpProviderSyncService $sync): array
    {
        return $sync->sync((bool) $request->boolean('dry_run'), $this->providerOverrides($request));
    }

    /**
     * 返回供应商配置摘要和最近同步日志，Token 永不出现在响应中。
     */
    public function providerStatus(IpProviderSyncService $sync): array
    {
        $configuration = $sync->configuration();
        unset($configuration['token']);

        $logs = [];
        if (Schema::hasTable('cyber_cloak_ip_provider_sync_logs')) {
            $logs = DB::table('cyber_cloak_ip_provider_sync_logs')
                ->orderByDesc('id')
                ->limit(20)
                ->get(['provider_code', 'status', 'fetched_count', 'accepted_count', 'error', 'started_at', 'finished_at'])
                ->all();
        }

        return [
            'configuration' => $configuration,
            'unavailable'   => $sync->isUnavailable(),
            'logs'          => $logs,
        ];
    }

    /**
     * 更新展示订单审核状态；approved 表示允许进入正常履约处理。
     */
    public function reviewOrder(Request $request, Order $order): mixed
    {
        if (! Schema::hasColumn('orders', 'catalog_review_status')) {
            return json_fail('请先执行阶段六数据库迁移');
        }

        $data = $request->validate([
            'status' => 'required|in:pending,approved,rejected',
            'note'   => 'nullable|string|max:2000',
        ]);
        $order->catalog_review_status = $data['status'];
        $order->catalog_reviewed_by   = auth()->id();
        $order->catalog_reviewed_at   = now();
        $order->catalog_review_note   = $data['note'] ?? null;
        $order->saveOrFail();

        return json_success('展示订单审核状态已更新');
    }

    /**
     * 仅保留允许后台临时覆盖的供应商参数。
     *
     * @return array<string,mixed>
     */
    private function providerOverrides(Request $request): array
    {
        $overrides = [];
        foreach (['provider', 'endpoint'] as $key) {
            if ($request->filled($key)) {
                $overrides[$key] = (string) $request->input($key);
            }
        }
        if ($request->filled('timeout')) {
            $overrides['timeout'] = (int) $request->input('timeout');
        }

        return $overrides;
    }
}
