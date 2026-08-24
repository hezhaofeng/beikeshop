<?php

namespace Plugin\CyberCloak\Controllers;

use Beike\Admin\Http\Controllers\Controller;
use Beike\Repositories\SettingRepo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugin\CyberCloak\Jobs\RebuildProductMappingsJob;
use Plugin\CyberCloak\Services\AccessKeyService;
use Plugin\CyberCloak\Services\CatalogRouteService;
use Plugin\CyberCloak\Services\HomeBannerImageMappingService;
use Plugin\CyberCloak\Services\HomeDesignMappingService;
use Plugin\CyberCloak\Services\MappingTaskService;
use Plugin\CyberCloak\Services\SkuMappingService;
use Plugin\CyberCloak\Services\TrafficRiskAuditService;

class AdminCyberCloakController extends Controller
{
    /**
     * 保存 CyberCloak 设置，名单类字段始终持久化为干净的 JSON 数组。
     */
    public function updateSettings(Request $request): mixed
    {
        $columns = collect(require dirname(__DIR__) . '/columns.php')->keyBy('name');
        $fields  = [];
        $rules   = [];

        foreach ($columns as $name => $column) {
            if (! $request->has($name)) {
                continue;
            }

            $value = $request->input($name);
            if (($column['type'] ?? '') === 'select-multiple') {
                $value = $this->normalizeMultiValue($value);
            } elseif (($column['type'] ?? '') === 'bool') {
                $value = $request->boolean($name) ? 1 : 0;
            }

            $fields[$name] = $value;
            $rules[$name]  = $column['rules'] ?? 'nullable';
        }

        if ($request->has('status')) {
            $fields['status'] = $request->boolean('status') ? 1 : 0;
            $rules['status']  = 'nullable|boolean';
        }

        $validator = app('validator')->make($fields, $rules);
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        SettingRepo::update('plugin', 'cyber_cloak', $validator->validated());
        Log::info('CyberCloak 后台保存插件配置', [
            'fields'   => array_keys($validator->validated()),
            'admin_id' => auth()->id(),
        ]);

        return redirect(admin_route('plugins.edit', ['cyber_cloak']))->with('success', 'CyberCloak 配置已保存');
    }

    /**
     * 返回只读运行状态，便于在保存规则前确认插件和两套商品库均已可用。
     */
    public function runtimeStatus(): mixed
    {
        return json_success('运行状态已更新', [
            'plugin'      => $this->pluginStatus(),
            'connections' => [
                'real'   => $this->connectionStatus('real'),
                'public' => $this->connectionStatus('public'),
            ],
        ]);
    }

    /**
     * 返回映射版本、待确认 SKU 和当前路由统计，供插件编辑页展示。
     */
    public function mappingStatus(Request $request, SkuMappingService $mappings, HomeDesignMappingService $designMappings, HomeBannerImageMappingService $bannerImages, MappingTaskService $tasks): mixed
    {
        try {
            $dashboard                  = $mappings->dashboard($request->input('version'));
            $dashboard['home']          = $designMappings->scan();
            $dashboard['banner_images'] = $bannerImages->scan();
            // 部署代码与插件迁移之间的短暂窗口不应阻断原有映射状态查看。
            $dashboard['task'] = rescue(fn (): ?array => $tasks->latest(), null, false);

            return json_success('映射状态已更新', $dashboard);
        } catch (\Throwable $exception) {
            return json_fail('读取映射状态失败：' . $exception->getMessage());
        }
    }

    /**
     * 返回风险 IP 档案，供高级设置内的人工审核面板使用。
     */
    public function trafficRiskProfiles(Request $request, TrafficRiskAuditService $audit): mixed
    {
        $data = $request->validate([
            'status'   => 'nullable|in:unreviewed,trusted,suspicious,blocked',
            'page'     => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return json_success('风险 IP 审核数据已读取', $audit->profiles(
            $data['status'] ?? null,
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? 20),
        ));
    }

    /**
     * 保存风险 IP 的人工审核结论；封禁状态会参与后续无凭据漏斗判断。
     */
    public function reviewTrafficRisk(int $profile, Request $request, TrafficRiskAuditService $audit): mixed
    {
        $data = $request->validate([
            'status' => 'required|in:unreviewed,trusted,suspicious,blocked',
            'note'   => 'nullable|string|max:2000',
        ]);

        if (! $audit->review($profile, $data['status'], $data['note'] ?? null, auth()->id())) {
            return json_fail('风险 IP 档案不存在或审核状态未变化');
        }

        return json_success('风险 IP 审核状态已更新');
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
    public function rebuildMappings(Request $request, MappingTaskService $tasks): mixed
    {
        $data = $request->validate([
            'mode'  => 'required|in:exact,candidate,random',
            'force' => 'nullable|boolean',
        ]);

        return $this->submitProductMappingTask($data, false, $tasks);
    }

    /**
     * 一键重建商品 SKU 映射，并在成功发布后只更新商品稳定链接。
     *
     * 分类链接和首页 Banner 检查由各自按钮负责，避免一次操作覆盖不相关的路由数据。
     */
    public function rebuildProductMappings(Request $request, MappingTaskService $tasks): mixed
    {
        $data = $request->validate([
            'mode'    => 'nullable|in:exact,candidate,random',
            'force'   => 'nullable|boolean',
            'version' => 'nullable|string|max:64',
        ]);

        return $this->submitProductMappingTask($data, true, $tasks);
    }

    /**
     * 返回任务状态给管理页面轮询，避免长耗时映射占用 Cloudflare 代理请求。
     */
    public function mappingTask(string $task, MappingTaskService $tasks): mixed
    {
        try {
            $data = $tasks->find($task);
            if (! $data) {
                return json_fail('映射任务不存在', [], 404);
            }

            return json_success('映射任务状态已更新', $data);
        } catch (\Throwable $exception) {
            return json_fail('读取映射任务失败：' . $exception->getMessage());
        }
    }

    /**
     * 将生成或发布商品映射的请求入队；是否同步商品链接由调用入口决定。
     *
     * @param array<string,mixed> $data
     */
    private function submitProductMappingTask(array $data, bool $syncRoutes, MappingTaskService $tasks): mixed
    {
        $task = null;

        try {
            $version = trim((string) ($data['version'] ?? ''));
            $task    = $tasks->createProductTask(
                $version === '' ? 'rebuild' : 'publish',
                $version === '' ? (string) ($data['mode'] ?? 'exact') : null,
                $version !== '' ? $version : null,
                $syncRoutes,
                (bool) ($data['force'] ?? false),
                auth()->id() ? (int) auth()->id() : null
            );
            RebuildProductMappingsJob::dispatch($task['id']);
            Log::info('CyberCloak 后台提交商品映射任务', [
                'task_id'     => $task['id'],
                'action'      => $task['action'],
                'mode'        => $task['mode'],
                'sync_routes' => $task['sync_routes'],
                'admin_id'    => auth()->id(),
            ]);

            return json_success('商品映射任务已提交，请等待后台执行', ['task' => $task]);
        } catch (\Throwable $exception) {
            if ($task) {
                $tasks->fail($task['id'], $exception);
            }

            return json_fail('提交商品映射任务失败：' . $exception->getMessage());
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
     * 将当前首页装修中的真实 Banner 图片登记到映射面板。
     */
    public function syncBannerImages(HomeBannerImageMappingService $bannerImages): mixed
    {
        try {
            $result = $bannerImages->sync();
            Log::info('CyberCloak 后台同步首页 Banner 图片映射', [
                'image_count' => $result['image_count'],
                'configured'  => $result['configured'],
                'admin_id'    => auth()->id(),
            ]);

            return json_success('首页 Banner 图片已同步到映射面板', $result);
        } catch (\Throwable $exception) {
            return json_fail('首页 Banner 图片同步失败：' . $exception->getMessage());
        }
    }

    /**
     * 保存一条真实 Banner 到 Cloak 图片的目标路径或 JSON 结构。
     */
    public function updateBannerImage(int $mapping, Request $request, HomeBannerImageMappingService $bannerImages): mixed
    {
        $data = $request->validate([
            'public_image' => 'nullable|string|max:65535',
            'status'       => 'nullable|in:active,disabled',
        ]);

        try {
            $result = $bannerImages->update(
                $mapping,
                $data['public_image'] ?? null,
                (string) ($data['status'] ?? 'active')
            );
            Log::info('CyberCloak 后台保存首页 Banner 图片映射', [
                'mapping_id' => $mapping,
                'status'     => $data['status'] ?? 'active',
                'admin_id'   => auth()->id(),
            ]);

            return json_success('首页 Banner 图片映射已保存', $result);
        } catch (\Throwable $exception) {
            return json_fail('首页 Banner 图片映射保存失败：' . $exception->getMessage());
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
        ]);

        try {
            $result = $mappings->confirm(
                (string) $data['version'],
                (int) $data['real_sku_id'],
                (int) $data['public_sku_id']
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
    public function publishMapping(Request $request, MappingTaskService $tasks): mixed
    {
        $data = $request->validate(['version' => 'required|string|max:64']);

        return $this->submitProductMappingTask($data, false, $tasks);
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
    public function keys(AccessKeyService $keys): mixed
    {
        $items = array_map(function (array $record) use ($keys): array {
            $id       = (string) $record['id'];
            $plainKey = $keys->plainKeyForShare($id);
            $validKey = $keys->findValidById($id) !== null;

            return [
                'id'           => $record['id'],
                'status'       => $record['status'],
                'expires_at'   => $record['expires_at'],
                'hash'         => $record['hash'] ? substr((string) $record['hash'], 0, 8) . '...' : null,
                'shareable'    => $plainKey !== null && $validKey,
                'share_reason' => $plainKey === null
                    ? '该 key 仅保存了摘要，无法恢复原文；请重新创建后再分享'
                    : ($validKey ? null : '该 key 已停用或已过期，无法生成分享链接'),
            ];
        }, $keys->all());

        return json_success('访问 key 已读取', ['items' => $items]);
    }

    /**
     * 使用管理员提交的明文 key 创建摘要记录，响应不回传明文 key。
     */
    public function createKey(Request $request, AccessKeyService $keys): mixed
    {
        $data = $request->validate([
            'key'        => 'required|string|max:512',
            'expires_at' => 'nullable|date',
        ]);
        $expiresAt = $data['expires_at'] ?? null;
        // 配置页使用日期控件，选择某天时应在当天结束后才失效。
        if (is_string($expiresAt) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt)) {
            $expiresAt .= ' 23:59:59';
        }
        $record = $keys->create($data['key'], $expiresAt ? new \DateTimeImmutable($expiresAt) : null);

        return json_success('访问 key 已保存', [
            'id'         => $record['id'],
            'status'     => $record['status'],
            'expires_at' => $record['expires_at'],
            'hash'       => substr((string) $record['hash'], 0, 8) . '...',
        ]);
    }

    /**
     * 生成单条访问 key 的原文分享链接；历史摘要记录无法逆向恢复原文。
     */
    public function shareKey(string $id, AccessKeyService $keys): mixed
    {
        $plainKey = $keys->plainKeyForShare($id);
        if ($plainKey === null) {
            return json_fail('该访问 key 仅保存了摘要，无法恢复原文；请使用原 key 重新创建后再分享');
        }
        if ($keys->findValidById($id) === null) {
            return json_fail('访问 key 已停用或已过期，无法生成分享链接');
        }

        $keyParameter = trim((string) config('cyber_cloak.key_parameter', 'key'));
        if ($keyParameter === '') {
            return json_fail('访问 key 参数名不能为空');
        }

        $baseUrl = trim((string) config('app.url', '')) ?: url('/');
        // 分享链接保留后台创建的 key 原文，便于与手工使用的 key 直接核对。
        $url     = rtrim($baseUrl, '/') . '/?' . $keyParameter . '=' . $plainKey;

        return json_success('访问链接已生成', ['url' => $url]);
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
     * 读取插件启用状态；插件尚未安装或设置表暂不可用时返回可读的未知状态。
     *
     * @return array{installed:bool,enabled:bool,label:string}
     */
    private function pluginStatus(): array
    {
        try {
            $plugin = app('plugin')->getPlugin('cyber_cloak');
            if (! $plugin || ! $plugin->getInstalled()) {
                return ['installed' => false, 'enabled' => false, 'label' => '未安装'];
            }

            $enabled = $plugin->getEnabled();

            return ['installed' => true, 'enabled' => $enabled, 'label' => $enabled ? '已启用' : '已停用'];
        } catch (\Throwable) {
            return ['installed' => false, 'enabled' => false, 'label' => '状态读取失败'];
        }
    }

    /**
     * 实际打开 PDO 连接探测真实库或展示库，不返回主机、账号和密码等部署敏感配置。
     *
     * @return array{connection:string,database:?string,connected:bool,label:string}
     */
    private function connectionStatus(string $mode): array
    {
        $connection = (string) config("cyber_cloak.connections.{$mode}", $mode === 'public' ? 'catalog_public' : 'mysql');
        if (! is_array(config("database.connections.{$connection}"))) {
            return ['connection' => $connection, 'database' => null, 'connected' => false, 'label' => '未配置'];
        }

        try {
            $database = (string) DB::connection($connection)->getDatabaseName();
            DB::connection($connection)->getPdo();

            return [
                'connection' => $connection,
                'database'   => $database !== '' ? $database : null,
                'connected'  => true,
                'label'      => $database !== '' ? "已连接（{$database}）" : '已连接',
            ];
        } catch (\Throwable) {
            return ['connection' => $connection, 'database' => null, 'connected' => false, 'label' => '连接失败'];
        }
    }

    /**
     * 过滤多选控件的空占位值，兼容历史文本、数组和重复选项。
     *
     * @return array<int,string>
     */
    private function normalizeMultiValue(mixed $value): array
    {
        if (! is_array($value)) {
            $value = $value === null ? [] : [$value];
        }

        $values = array_map(
            static fn (mixed $item): string => strtoupper(trim((string) $item)),
            $value
        );

        return array_values(array_unique(array_filter($values, static fn (string $item): bool => $item !== '')));
    }
}
