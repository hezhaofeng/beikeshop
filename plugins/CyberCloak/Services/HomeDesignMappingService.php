<?php

namespace Plugin\CyberCloak\Services;

/**
 * 扫描首页装修中的 Banner 链接，验证商品和分类是否已有已发布路由。
 *
 * 首页装修始终维护真实库数据，Cloak 模式只在运行时通过 catalog_route_maps 转换链接，
 * 因此这里不复制或修改装修配置，只提供后台可视化检查结果。
 */
class HomeDesignMappingService
{
    /**
     * 注入路由映射服务，首页扫描只读已发布的商品和分类路由。
     */
    public function __construct(private readonly CatalogRouteService $routes)
    {
    }

    /**
     * 返回首页装修 Banner 映射统计和未映射链接明细。
     *
     * 递归扫描所有模块的 {type, value} 链接，新增模块只要复用装修器的链接结构即可自动纳入检查。
     *
     * @return array<string,mixed>
     */
    public function scan(): array
    {
        $setting = system_setting('base.design_setting', ['modules' => []]);
        $modules = is_array($setting) && is_array($setting['modules'] ?? null)
            ? $setting['modules']
            : [];
        $links = [];

        foreach ($modules as $moduleIndex => $module) {
            if (! is_array($module)) {
                continue;
            }

            $moduleCode = (string) ($module['code'] ?? '');
            $moduleId   = (string) ($module['module_id'] ?? '');
            $this->collectLinks(
                $module['content'] ?? [],
                "modules[{$moduleIndex}].content",
                $moduleCode,
                $moduleId,
                $links
            );
        }

        $productIds  = $this->linkIds($links, 'product');
        $categoryIds = $this->linkIds($links, 'category');
        $productMap  = $productIds === []
            ? []
            : $this->routes->publicRecordIdsForRealRecords('product', $productIds);
        $categoryMap = $categoryIds === []
            ? []
            : $this->routes->publicRecordIdsForRealRecords('category', $categoryIds);

        $counts = [
            'product'      => 0,
            'category'     => 0,
            'custom'       => 0,
            'static'       => 0,
            'other'        => 0,
            'mapped'       => 0,
            'unmapped'     => 0,
            'not_required' => 0,
            'unsupported'  => 0,
        ];
        $unmapped = [];

        foreach ($links as &$link) {
            $type = $link['type'];
            if (array_key_exists($type, $counts)) {
                $counts[$type]++;
            } else {
                $counts['other']++;
            }

            if (in_array($type, ['product', 'category'], true)) {
                $id             = (int) $link['value'];
                $map            = $type === 'product' ? $productMap : $categoryMap;
                $link['status'] = $id > 0 && ! empty($map[$id]) ? 'mapped' : 'unmapped';
                $counts[$link['status']]++;
                if ($link['status'] === 'unmapped') {
                    $unmapped[] = $link;
                }
            } elseif (in_array($type, ['custom', 'static'], true)) {
                $link['status'] = 'not_required';
                $counts['not_required']++;
            } else {
                $link['status'] = 'unsupported';
                $counts['unsupported']++;
                $unmapped[] = $link;
            }
        }
        unset($link);

        return [
            'module_count' => count($modules),
            'banner_count' => count($links),
            'link_count'   => count($links),
            'counts'       => $counts,
            'mapped'       => $counts['mapped'],
            'unmapped'     => $counts['unmapped'],
            'not_required' => $counts['not_required'],
            'unresolved'   => array_slice($unmapped, 0, 100),
            'truncated'    => count($unmapped) > 100,
            'scanned_at'   => now()->toDateTimeString(),
        ];
    }

    /**
     * 从装修内容中递归提取标准链接，兼容幻灯片、图片 Banner、图标和后续自定义模块。
     *
     * @param mixed                          $value
     * @param array<int,array<string,mixed>> $links
     */
    private function collectLinks(mixed $value, string $path, string $moduleCode, string $moduleId, array &$links): void
    {
        if (! is_array($value)) {
            return;
        }

        if (array_key_exists('type', $value) && array_key_exists('value', $value)) {
            $links[] = [
                'module_code' => $moduleCode,
                'module_id'   => $moduleId,
                'path'        => $path,
                'type'        => strtolower(trim((string) $value['type'])),
                'value'       => $this->linkValue($value['type'] ?? '', $value['value'] ?? ''),
            ];

            return;
        }

        foreach ($value as $key => $child) {
            $this->collectLinks($child, $path . '.' . $key, $moduleCode, $moduleId, $links);
        }
    }

    /**
     * 商品和分类链接只能使用正整数 ID，字符串 URL 或空值由扫描结果标为未映射。
     */
    private function linkValue(string $type, mixed $value): mixed
    {
        return in_array(strtolower(trim($type)), ['product', 'category'], true)
            ? (int) $value
            : (string) $value;
    }

    /**
     * 提取指定类型的去重 ID，减少首页重复 Banner 对路由表的查询次数。
     *
     * @param array<int,array<string,mixed>> $links
     * @return array<int,int>
     */
    private function linkIds(array $links, string $type): array
    {
        $ids = [];
        foreach ($links as $link) {
            if ($link['type'] !== $type) {
                continue;
            }

            $id = (int) $link['value'];
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
