<?php

namespace Plugin\CyberCloak\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CatalogImportService
{
    /**
     * 从 JSON 文件导入展示库商品、分类、品牌、描述、分类路径和 SKU。
     *
     * 文件使用稳定 ID 时可以重复导入；没有 ID 的 SKU 会按非空 SKU 字段幂等更新。
     *
     * @return array{brands:int,categories:int,products:int,skus:int}
     */
    public function import(string $file, string $connection = 'catalog_public', bool $truncate = false, bool $dryRun = false): array
    {
        if (! is_file($file) || ! is_readable($file)) {
            throw new InvalidArgumentException("导入文件不存在或不可读：{$file}");
        }

        $payload = json_decode((string) file_get_contents($file), true);
        if (! is_array($payload)) {
            throw new InvalidArgumentException('导入文件必须是 JSON 对象');
        }

        $database = DB::connection($connection);
        $data     = $this->normalizePayload($payload);
        if ($dryRun) {
            return $this->countPayload($data);
        }

        return $database->transaction(function () use ($database, $data, $truncate): array {
            if ($truncate) {
                $this->truncateCatalog($database);
            }

            $categoryIds = $this->importCategories($database, $data['categories']);
            $this->rebuildCategoryPaths($database);
            $brandIds    = $this->importBrands($database, $data['brands']);

            return $this->importProducts($database, $data['products'], $categoryIds, $brandIds);
        });
    }

    /**
     * 校验并统一导入文件的顶层结构，避免把任意字段直接写入数据库。
     *
     * @return array{brands:array<int,array<string,mixed>>,categories:array<int,array<string,mixed>>,products:array<int,array<string,mixed>>}
     */
    private function normalizePayload(array $payload): array
    {
        foreach (['brands', 'categories', 'products'] as $key) {
            if (isset($payload[$key]) && ! is_array($payload[$key])) {
                throw new InvalidArgumentException("{$key} 必须是数组");
            }
        }

        foreach ($payload['products'] ?? [] as $product) {
            $product = $this->requireArray($product, '商品');
            foreach ((array) ($product['skus'] ?? []) as $sku) {
                $this->validateSkuIdentity($this->requireArray($sku, 'SKU'));
            }
        }

        return [
            'brands'     => array_values($payload['brands'] ?? []),
            'categories' => array_values($payload['categories'] ?? []),
            'products'   => array_values($payload['products'] ?? []),
        ];
    }

    /**
     * 仅统计输入数据，供上线前校验格式使用。
     */
    private function countPayload(array $data): array
    {
        return [
            'brands'     => count($data['brands']),
            'categories' => count($data['categories']),
            'products'   => count($data['products']),
            'skus'       => array_sum(array_map(fn (array $product): int => count($product['skus'] ?? []), $data['products'])),
        ];
    }

    /**
     * 按依赖顺序清理展示库，调用方必须显式传入 truncate。
     */
    private function truncateCatalog($database): void
    {
        foreach (['product_categories', 'product_descriptions', 'product_skus', 'products', 'category_paths', 'category_descriptions', 'categories', 'brands'] as $table) {
            $database->table($table)->delete();
        }
    }

    /**
     * 导入品牌并返回源 ID 到数据库 ID 的映射。
     *
     * @return array<string|int,int>
     */
    private function importBrands($database, array $brands): array
    {
        $ids = [];
        foreach ($brands as $brand) {
            $brand = $this->requireArray($brand, '品牌');
            $data  = [
                'name'       => (string) ($brand['name'] ?? ''),
                'first'      => (string) ($brand['first'] ?? ''),
                'logo'       => (string) ($brand['logo'] ?? ''),
                'sort_order' => (int) ($brand['sort_order'] ?? 0),
                'active'     => (bool) ($brand['active'] ?? $brand['status'] ?? true),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $id = $this->upsertWithOptionalId($database, 'brands', $brand, $data);
            if (array_key_exists('id', $brand)) {
                $ids[(string) $brand['id']] = $id;
            }
        }

        return $ids;
    }

    /**
     * 导入分类和分类描述，返回源 ID 到数据库 ID 的映射。
     *
     * @return array<string|int,int>
     */
    private function importCategories($database, array $categories): array
    {
        $ids = [];
        $records = [];

        // 先写入所有分类并建立源 ID 映射，避免导入文件中的父分类排在子分类之后。
        foreach ($categories as $category) {
            $category = $this->requireArray($category, '分类');
            $data     = [
                'parent_id'  => 0,
                'position'   => (int) ($category['position'] ?? 0),
                'image'      => (string) ($category['image'] ?? ''),
                'active'     => (bool) ($category['active'] ?? true),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $id = $this->upsertWithOptionalId($database, 'categories', $category, $data);
            if (array_key_exists('id', $category)) {
                $ids[(string) $category['id']] = $id;
            }

            $records[] = ['id' => $id, 'parent_id' => $category['parent_id'] ?? 0];

            foreach ($this->descriptions($category) as $locale => $description) {
                $database->table('category_descriptions')->updateOrInsert(
                    ['category_id' => $id, 'locale' => (string) $locale],
                    $this->descriptionData($description)
                );
            }
        }

        // 第二遍再写父级，确保父级可引用源 ID 或已经存在的实际数据库 ID。
        foreach ($records as $record) {
            $parentKey = (string) $record['parent_id'];
            $parentId  = $ids[$parentKey] ?? (int) $record['parent_id'];
            $database->table('categories')->where('id', $record['id'])->update([
                'parent_id'  => max(0, $parentId),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    /**
     * 根据展示库分类的 parent_id 重建完整路径，路径顺序与主库一致。
     */
    private function rebuildCategoryPaths($database): void
    {
        $categories = $database->table('categories')->get(['id', 'parent_id'])->map(static fn ($category): array => [
            'id'        => (int) $category->id,
            'parent_id' => (int) $category->parent_id,
        ])->all();
        $rows = $this->buildCategoryPathRows($categories);

        $database->table('category_paths')->delete();
        foreach (array_chunk($rows, 1000) as $chunk) {
            $database->table('category_paths')->insert(array_map(static fn (array $row): array => $row + [
                'created_at' => now(),
                'updated_at' => now(),
            ], $chunk));
        }
    }

    /**
     * 将分类树转换为 category_paths 行，缺失父级按根分类处理，循环父级直接拒绝导入。
     *
     * @param array<int,array{id:int,parent_id:int}> $categories
     * @return array<int,array{category_id:int,path_id:int,level:int}>
     */
    private function buildCategoryPathRows(array $categories): array
    {
        $byId = [];
        foreach ($categories as $category) {
            $byId[(int) $category['id']] = [
                'id'        => (int) $category['id'],
                'parent_id' => (int) $category['parent_id'],
            ];
        }

        $rows = [];
        foreach ($byId as $categoryId => $category) {
            $path    = [];
            $visited = [];
            $current = $categoryId;

            while ($current > 0 && isset($byId[$current])) {
                if (isset($visited[$current])) {
                    throw new InvalidArgumentException("分类 {$categoryId} 存在循环父级关系");
                }

                $visited[$current] = true;
                $path[]             = $current;
                $current             = $byId[$current]['parent_id'];
            }

            foreach (array_reverse($path) as $level => $pathId) {
                $rows[] = [
                    'category_id' => $categoryId,
                    'path_id'     => $pathId,
                    'level'       => $level,
                ];
            }
        }

        return $rows;
    }

    /**
     * 导入商品主体、描述、SKU和分类关系。
     *
     * @return array{brands:int,categories:int,products:int,skus:int}
     */
    private function importProducts($database, array $products, array $categoryIds, array $brandIds): array
    {
        $count = ['brands' => count($brandIds), 'categories' => count($categoryIds), 'products' => 0, 'skus' => 0];
        foreach ($products as $product) {
            $product = $this->requireArray($product, '商品');
            $data    = [
                'brand_id'     => $brandIds[(string) ($product['brand_id'] ?? '')] ?? (int) ($product['brand_id'] ?? 0),
                'images'       => $this->json($product['images'] ?? []),
                'price'        => (float) ($product['price'] ?? 0),
                'video'        => (string) ($product['video'] ?? ''),
                'position'     => (int) ($product['position'] ?? 0),
                'active'       => (bool) ($product['active'] ?? true),
                'variables'    => $this->json($product['variables'] ?? []),
                'tax_class_id' => (int) ($product['tax_class_id'] ?? 0),
                // 商品重量属于 products 主表，必须与爬虫的 p.weight 查询保持同一字段契约。
                'weight'       => (float) ($product['weight'] ?? 0),
                'weight_class' => (string) ($product['weight_class'] ?? ''),
                'shipping'     => (bool) ($product['shipping'] ?? true),
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
            // 未提供销量时保留已有值，避免重复导入目录意外清零商品销量。
            if (array_key_exists('sales', $product)) {
                $data['sales'] = (int) $product['sales'];
            }
            $productId = $this->upsertWithOptionalId($database, 'products', $product, $data);
            $count['products']++;

            foreach ($this->descriptions($product) as $locale => $description) {
                $database->table('product_descriptions')->updateOrInsert(
                    ['product_id' => $productId, 'locale' => (string) $locale],
                    $this->descriptionData($description)
                );
            }

            $database->table('product_categories')->where('product_id', $productId)->delete();
            foreach ((array) ($product['category_ids'] ?? []) as $categoryId) {
                $mappedId = $categoryIds[(string) $categoryId] ?? (int) $categoryId;
                if ($mappedId > 0) {
                    $database->table('product_categories')->updateOrInsert([
                        'product_id'  => $productId,
                        'category_id' => $mappedId,
                    ], ['updated_at' => now(), 'created_at' => now()]);
                }
            }

            foreach ((array) ($product['skus'] ?? []) as $sku) {
                $sku     = $this->requireArray($sku, 'SKU');
                $skuCode = trim((string) ($sku['sku'] ?? ''));
                $skuId   = $sku['id'] ?? null;
                $this->validateSkuIdentity($sku);
                // 只有稳定 id 的历史数据允许空 sku；用可重复的占位值避免唯一索引冲突。
                if ($skuCode === '' && $skuId !== null && $skuId !== '') {
                    $skuCode = 'IMPORT-' . (string) $skuId;
                }
                $skuData = [
                    'product_id'   => $productId,
                    'variants'     => $this->json($sku['variants'] ?? []),
                    'position'     => (int) ($sku['position'] ?? 0),
                    'images'       => $this->json($sku['images'] ?? []),
                    'model'        => (string) ($sku['model'] ?? ''),
                    'sku'          => $skuCode,
                    'price'        => (float) ($sku['price'] ?? $product['price'] ?? 0),
                    'origin_price' => (float) ($sku['origin_price'] ?? 0),
                    'cost_price'   => (float) ($sku['cost_price'] ?? 0),
                    'weight'       => (float) ($sku['weight'] ?? 0),
                    'quantity'     => (int) ($sku['quantity'] ?? 0),
                    'is_default'   => (bool) ($sku['is_default'] ?? false),
                    'active'       => (bool) ($sku['active'] ?? true),
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ];
                if (array_key_exists('id', $sku) && $sku['id'] !== null && $sku['id'] !== '') {
                    $database->table('product_skus')->updateOrInsert(['id' => (int) $sku['id']], $skuData);
                } else {
                    $database->table('product_skus')->updateOrInsert(['sku' => $skuData['sku']], $skuData);
                }
                $count['skus']++;
            }
        }

        return $count;
    }

    /**
     * 校验 SKU 至少有一个可重复使用的稳定身份。
     */
    private function validateSkuIdentity(array $sku): void
    {
        $skuId   = $sku['id'] ?? null;
        $skuCode = trim((string) ($sku['sku'] ?? ''));
        if (($skuId === null || $skuId === '') && $skuCode === '') {
            throw new InvalidArgumentException('SKU 必须提供 id 或非空 sku，不能生成随机身份');
        }
    }

    /**
     * 插入或更新带可选主键的记录，并返回实际数据库 ID。
     */
    private function upsertWithOptionalId($database, string $table, array $source, array $data): int
    {
        if (isset($source['id'])) {
            $id = (int) $source['id'];
            $database->table($table)->updateOrInsert(['id' => $id], $data);

            return $id;
        }

        return (int) $database->table($table)->insertGetId($data);
    }

    /**
     * 将描述兼容为 locale => 字段数组格式。
     *
     * @return array<string,array<string,mixed>>
     */
    private function descriptions(array $record): array
    {
        $descriptions = $record['descriptions'] ?? [];
        if (! is_array($descriptions)) {
            throw new InvalidArgumentException('descriptions 必须是数组');
        }
        if (array_is_list($descriptions)) {
            $result = [];
            foreach ($descriptions as $description) {
                if (is_array($description) && isset($description['locale'])) {
                    $result[(string) $description['locale']] = $description;
                }
            }

            return $result;
        }

        return $descriptions;
    }

    /**
     * 提取描述字段，忽略导入文件中的未知字段。
     */
    private function descriptionData(mixed $description): array
    {
        $description = $this->requireArray($description, '描述');

        return [
            'name'             => (string) ($description['name'] ?? ''),
            'content'          => (string) ($description['content'] ?? ''),
            'meta_title'       => (string) ($description['meta_title'] ?? ''),
            'meta_description' => (string) ($description['meta_description'] ?? ''),
            'meta_keywords'    => (string) ($description['meta_keywords'] ?? $description['meta_keyword'] ?? ''),
            'updated_at'       => now(),
            'created_at'       => now(),
        ];
    }

    /**
     * 将数组编码为数据库 JSON 字符串。
     */
    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * 确保输入记录是对象数组。
     */
    private function requireArray(mixed $value, string $label): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("{$label}记录必须是对象");
        }

        return $value;
    }
}
