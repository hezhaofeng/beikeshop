<?php

namespace Plugin\Meilisearch\Services;

use Beike\Models\Product;

class ProductDocument
{
    /**
     * 构建单个商品在指定商品库和语言下的索引文档。
     *
     * 展示库文档只包含调用方已经按 CyberCloak 发布映射过滤后的 SKU，索引内不保留任何
     * 未发布的展示 SKU，避免用内部 SKU 反查出对应的展示商品。
     */
    public function make(Product $product, string $catalog, string $locale): array
    {
        CatalogContext::assertCatalog($catalog);

        $description = $product->descriptions->firstWhere('locale', $locale);
        $skus        = $product->skus;
        $masterSku   = $skus->firstWhere('is_default', true) ?: $skus->sortBy('position')->first();
        $skuCodes    = $skus->pluck('sku')->map(fn ($sku) => trim((string) $sku))->filter()->unique()->values()->all();
        $models      = $skus->pluck('model')->map(fn ($model) => trim((string) $model))->filter()->unique()->values()->all();

        // 展示库商品必须至少保留一个已发布 SKU，否则前台查询同样看不到它。
        $visible = (bool) $product->active
            && $product->deleted_at === null
            && (! CatalogContext::constrained($catalog) || $skus->isNotEmpty());

        return [
            'id'           => (int) $product->id,
            'name'         => (string) ($description?->name ?? ''),
            'sku'          => $skuCodes,
            'model'        => $models,
            // 规格化副本用于 SKU 精确匹配通道，规避分隔符分词带来的误召回。
            'sku_exact'    => $this->normalizeAll(array_merge($skuCodes, $models)),
            'category_ids' => $product->productCategories->pluck('category_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
            // 分类名称参与检索，使“手机配件”这类分类词也能召回该分类下的商品。
            'category_names' => $this->categoryNames($product, $locale),
            'brand_id'       => (int) ($product->brand_id ?? 0),
            'price'          => (float) ($masterSku?->price ?? 0),
            'active'         => $visible,
            'visible'        => $visible,
            'position'       => (int) ($product->position ?? 0),
            'sales'          => (int) ($product->sales ?? 0),
            'views'          => (int) ($product->views_count ?? 0),
            'created_at'     => $product->created_at?->timestamp ?? 0,
            'updated_at'     => $product->updated_at?->timestamp ?? 0,
        ];
    }

    /**
     * 生成 SKU 精确匹配键：去掉分隔符并统一大写，使 ABC-123 与 abc123 命中同一条记录。
     */
    public function normalize(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9]+/u', '', trim($value));

        return strtoupper((string) $value);
    }

    /**
     * 取商品所属分类及其父级分类在当前语言下的名称。
     *
     * 依赖调用方预加载 categories.descriptions 和 categories.paths.pathCategory.descriptions；
     * 未预加载时返回空数组而不是逐个商品回查，避免全量索引退化成 N+1 查询。
     *
     * @return array<int,string>
     */
    private function categoryNames(Product $product, string $locale): array
    {
        if (! $product->relationLoaded('categories')) {
            return [];
        }

        $names = [];
        foreach ($product->categories as $category) {
            $this->appendCategoryName($names, $category, $locale);

            // category_paths 一行对应一个祖先节点，包含自身和所有父级分类。
            // 关系未预加载时跳过，保证单条增量同步也不会隐式触发 N+1 查询。
            if (! $category->relationLoaded('paths')) {
                continue;
            }

            foreach ($category->paths as $path) {
                if (! $path->relationLoaded('pathCategory')) {
                    continue;
                }

                $pathCategory = $path->pathCategory;
                if ($pathCategory) {
                    $this->appendCategoryName($names, $pathCategory, $locale);
                }
            }
        }

        return array_values($names);
    }

    /**
     * 将一个分类的当前语言名称加入去重集合。
     *
     * @param array<string,string> $names
     */
    private function appendCategoryName(array &$names, object $category, string $locale): void
    {
        if (! $category->relationLoaded('descriptions')) {
            return;
        }

        $name = trim((string) $category->descriptions->firstWhere('locale', $locale)?->name);
        if ($name !== '') {
            $names[$name] = $name;
        }
    }

    /**
     * @param array<int,string> $values
     * @return array<int,string>
     */
    private function normalizeAll(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $key = $this->normalize((string) $value);
            if ($key !== '') {
                $normalized[$key] = $key;
            }
        }

        return array_values($normalized);
    }
}
