<?php

namespace Plugin\BestsellerSelector\Services;

use Beike\Models\CategoryPath;
use Beike\Repositories\CategoryRepo;
use Beike\Repositories\ProductRepo;
use Beike\Repositories\SettingRepo;
use Beike\Shop\Http\Resources\ProductSimple;
use Plugin\CyberCloak\Services\CatalogContentMappingService;

class BestsellerSelectionService
{
    private const SETTING_MODULES = 'modules';

    private const SETTING_MODE = 'mode';

    private const SETTING_PRODUCT_IDS = 'product_ids';

    private const MODE_AUTO = 'auto';

    private const MODE_MANUAL = 'manual';

    /**
     * 将后台保存的值统一为正整数 ID，并去重保留首次出现顺序。
     */
    public function normalizeIds(mixed $ids): array
    {
        $result = [];
        foreach ((array) $ids as $id) {
            $id = (int) (is_array($id) ? ($id['id'] ?? 0) : $id);
            if ($id > 0 && ! in_array($id, $result, true)) {
                $result[] = $id;
            }
        }

        return $result;
    }

    public function mode(): string
    {
        return plugin_setting('bestseller_selector.' . self::SETTING_MODE, self::MODE_AUTO) === self::MODE_MANUAL
            ? self::MODE_MANUAL
            : self::MODE_AUTO;
    }

    /**
     * 返回首页中可配置商品的模块实例；module_id 是装修器为每个实例生成的稳定标识。
     */
    public function homepageModules(): array
    {
        $designSettings = system_setting('base.design_setting', ['modules' => []]);
        $modules        = $designSettings['modules'] ?? [];
        $result         = [];

        foreach ((array) $modules as $module) {
            $code = (string) ($module['code'] ?? '');
            if (! in_array($code, ['bestseller', 'product', 'tab_product'], true)) {
                continue;
            }

            $moduleId = trim((string) ($module['module_id'] ?? ''));
            if ($moduleId === '') {
                continue;
            }

            $content = (array) ($module['content'] ?? []);
            $title   = data_get($content, 'title.' . locale())
                ?: data_get($content, 'title.' . admin_locale())
                ?: $this->moduleCodeLabel($code);
            $item    = [
                'id'    => $moduleId,
                'code'  => $code,
                'title' => (string) $title,
            ];

            if ($code === 'tab_product') {
                $item['tabs'] = [];
                foreach ((array) ($content['tabs'] ?? []) as $index => $tab) {
                    $tabTitle       = data_get($tab, 'title.' . locale())
                        ?: data_get($tab, 'title.' . admin_locale())
                        ?: ('选项卡 ' . ((int) $index + 1));
                    $item['tabs'][] = [
                        'index' => (int) $index,
                        'title' => (string) $tabTitle,
                    ];
                }
            }

            $result[] = $item;
        }

        return $result;
    }

    public function selectedIds(): array
    {
        return $this->normalizeIds(plugin_setting('bestseller_selector.' . self::SETTING_PRODUCT_IDS, []));
    }

    /**
     * 返回后台选品页需要的分类树数据；分类名称由 CategoryRepo 按当前后台语言生成。
     */
    public function categories(): array
    {
        return CategoryRepo::flatten(admin_locale(), false)->map(function ($category): array {
            $name = (string) ($category->name ?? '');

            return [
                'id'        => (int) $category->id,
                'name'      => $name,
                'parent_id' => (int) $category->parent_id,
                'level'     => substr_count($name, ' > ') + 1,
            ];
        })->values()->all();
    }

    /**
     * 按可选的分类及商品名称查询启用商品。父级分类显式展开为所有子孙分类，避免被店铺分类展示配置影响。
     */
    public function productsByCategory(?int $categoryId, int $page = 1, int $perPage = 50, string $name = ''): array
    {
        $perPage = min(max($perPage, 10), 100);
        $filters = [
            'active'      => 1,
            'sort'        => 'products.created_at',
            'order'       => 'desc',
        ];
        if ($categoryId) {
            $filters['category_id'] = $this->categoryAndDescendantIds($categoryId);
        }
        $name = trim($name);
        if ($name !== '') {
            $filters['name'] = $name;
        }

        $builder = ProductRepo::getBuilder($filters)->whereHas('masterSku');

        $paginator = $builder->paginate($perPage, ['*'], 'page', max($page, 1));
        $items     = ProductSimple::collection($paginator->getCollection())->jsonSerialize();
        $items     = array_map(function (array $item, $product): array {
            $item['created_at'] = optional($product->created_at)->format('Y-m-d H:i');

            return $item;
        }, $items, $paginator->getCollection()->all());

        return [
            'items'      => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ];
    }

    /**
     * 只保留仍然启用且有默认 SKU 的商品，防止后台保存后前台出现无效商品。
     */
    public function validSelectedIds(array $ids): array
    {
        $ids = $this->normalizeIds($ids);
        if ($ids === []) {
            return [];
        }

        $models = ProductRepo::getBuilder([
            'product_ids' => $ids,
            'active'      => 1,
        ])->whereHas('masterSku')->get();

        $existingIds = $models->pluck('id')->map(fn ($id): int => (int) $id)->all();

        // 查询会用 FIELD 保留输入顺序，但这里显式重组以防数据库或扩展 Hook 改写排序。
        return array_values(array_filter($ids, fn (int $id): bool => in_array($id, $existingIds, true)));
    }

    /**
     * 保存模式和商品顺序；数组字段由 SettingRepo 持久化为 JSON。
     */
    public function save(string $mode, array $ids): array
    {
        $mode = $mode === self::MODE_MANUAL ? self::MODE_MANUAL : self::MODE_AUTO;
        $ids  = $this->validSelectedIds($ids);

        // 空选项不能启用手动模式，否则首页热卖区会被意外清空。
        if ($mode === self::MODE_MANUAL && $ids === []) {
            $mode = self::MODE_AUTO;
        }

        SettingRepo::update('plugin', 'bestseller_selector', [
            self::SETTING_MODE        => $mode,
            self::SETTING_PRODUCT_IDS => $ids,
        ]);

        return $this->stateFor($mode, $ids);
    }

    /**
     * 按首页模块实例批量保存配置，选项卡商品按 module_id 和 tab 下标分别保存。
     */
    public function saveModules(array $moduleData): array
    {
        $available = collect($this->homepageModules())->keyBy('id');
        $saved     = [];

        foreach ($moduleData as $moduleId => $config) {
            $moduleId = (string) $moduleId;
            if (! $available->has($moduleId) || ! is_array($config)) {
                continue;
            }

            $module = $available->get($moduleId);
            $mode   = ($config['mode'] ?? self::MODE_AUTO) === self::MODE_MANUAL ? self::MODE_MANUAL : self::MODE_AUTO;
            $item   = ['mode' => $mode, 'product_ids' => []];

            if ($module['code'] === 'tab_product') {
                $item['tabs'] = [];
                $validTabs    = collect($module['tabs'] ?? [])->keyBy('index');
                foreach ((array) ($config['tabs'] ?? []) as $tabIndex => $tabConfig) {
                    $tabIndex = (int) $tabIndex;
                    if (! $validTabs->has($tabIndex) || ! is_array($tabConfig)) {
                        continue;
                    }
                    $tabIds = $this->validSelectedIds((array) ($tabConfig['product_ids'] ?? []));
                    // 选项卡提交了商品但未带 mode 时，按手动选品处理，兼容旧版面板 payload。
                    $tabMode                          = ($tabConfig['mode'] ?? ($tabIds !== [] ? self::MODE_MANUAL : $mode));
                    $item['tabs'][(string) $tabIndex] = [
                        'product_ids' => $tabIds,
                        'mode'        => $tabMode === self::MODE_MANUAL && $tabIds !== [] ? self::MODE_MANUAL : self::MODE_AUTO,
                    ];
                }
            } else {
                $ids                 = $this->validSelectedIds((array) ($config['product_ids'] ?? []));
                $item['product_ids'] = $ids;
                if ($mode === self::MODE_MANUAL && $ids === []) {
                    $item['mode'] = self::MODE_AUTO;
                }
            }

            $saved[$moduleId] = $item;
        }

        SettingRepo::storeValue(self::SETTING_MODULES, $saved, 'bestseller_selector', 'plugin');

        return $this->state();
    }

    /**
     * 返回保存后的商品资源，并保持数据库中保存的选择顺序。
     */
    public function state(): array
    {
        $configs = $this->moduleConfigs();
        // 旧版本只有一份热卖配置，映射到首页第一个热卖模块用于平滑升级。
        if ($configs === []) {
            foreach ($this->homepageModules() as $module) {
                if ($module['code'] === 'bestseller' && $this->mode() === self::MODE_MANUAL) {
                    $configs[$module['id']] = [
                        'mode'        => self::MODE_MANUAL,
                        'product_ids' => $this->selectedIds(),
                    ];

                    break;
                }
            }
        }
        $states  = [];
        foreach ($this->homepageModules() as $module) {
            $config = $configs[$module['id']] ?? ['mode' => self::MODE_AUTO, 'product_ids' => []];
            $state  = [
                'mode'        => $config['mode'] ?? self::MODE_AUTO,
                'product_ids' => $this->normalizeIds($config['product_ids'] ?? []),
                'products'    => $this->productsByIds($config['product_ids'] ?? []),
            ];
            if ($module['code'] === 'tab_product') {
                $state['tabs'] = [];
                foreach ($module['tabs'] ?? [] as $tab) {
                    $tabConfig                             = $config['tabs'][(string) $tab['index']] ?? ['mode' => self::MODE_AUTO, 'product_ids' => []];
                    $state['tabs'][(string) $tab['index']] = [
                        'mode'        => $tabConfig['mode'] ?? self::MODE_AUTO,
                        'product_ids' => $this->normalizeIds($tabConfig['product_ids'] ?? []),
                        'products'    => $this->productsByIds($tabConfig['product_ids'] ?? []),
                    ];
                }
            }
            $states[$module['id']] = $state;
        }

        return [
            'homepage_modules' => $this->homepageModules(),
            'modules'          => $states,
        ];
    }

    private function stateFor(string $mode, array $ids): array
    {
        $products = $this->productsByIds($ids);

        return [
            'mode'        => $mode,
            'product_ids' => $ids,
            'products'    => $products,
        ];
    }

    /**
     * 首页展示模式下，将真实库商品 ID 映射为当前展示库商品后输出。
     */
    public function applyHomepageSelection(array $data): array
    {
        $moduleId = trim((string) ($data['module_id'] ?? ''));
        $configs  = $this->moduleConfigs();
        $config   = $configs[$moduleId] ?? null;
        if (! is_array($config)) {
            // 兼容插件升级前的单一热卖配置。
            if (($data['module_code'] ?? '') === 'bestseller' && $this->mode() === self::MODE_MANUAL) {
                $data['products'] = $this->productsByIds($this->selectedIds());
            }

            return $data;
        }

        if (($data['module_code'] ?? '') === 'tab_product') {
            foreach ((array) ($data['tabs'] ?? []) as $index => $tab) {
                $tabConfig = $config['tabs'][(string) $index] ?? null;
                if (is_array($tabConfig) && ($tabConfig['mode'] ?? self::MODE_AUTO) === self::MODE_MANUAL) {
                    $data['tabs'][$index]['products'] = $this->productsByIds($tabConfig['product_ids'] ?? []);
                }
            }

            return $data;
        }

        if (($config['mode'] ?? self::MODE_AUTO) === self::MODE_MANUAL) {
            $data['products'] = $this->productsByIds($config['product_ids'] ?? []);
        }

        return $data;
    }

    /**
     * 读取新模块配置；没有新配置时返回空数组，旧字段由首页热卖逻辑单独兼容。
     */
    private function moduleConfigs(): array
    {
        $value = plugin_setting('bestseller_selector.' . self::SETTING_MODULES, []);

        return is_array($value) ? $value : [];
    }

    private function moduleCodeLabel(string $code): string
    {
        return [
            'bestseller'  => '热卖商品模块',
            'product'     => '商品模块',
            'tab_product' => '选项卡商品模块',
        ][$code] ?? $code;
    }

    private function productsByIds(array $ids): array
    {
        $displayIds = $ids;
        if (app()->bound(CatalogContentMappingService::class)) {
            $displayIds = app(CatalogContentMappingService::class)->mapProductIds($ids);
        }

        if ($displayIds === []) {
            return [];
        }

        return ProductRepo::getProductsByIds($displayIds)->jsonSerialize();
    }

    /**
     * 通过分类路径表展开父级分类。传入全部后代 ID 后，无论店铺是否开启子分类展示都能覆盖一级/二级分类。
     */
    private function categoryAndDescendantIds(int $categoryId): array
    {
        $ids = CategoryPath::query()
            ->where('path_id', $categoryId)
            ->pluck('category_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $this->normalizeIds([$categoryId, ...$ids]);
    }
}
