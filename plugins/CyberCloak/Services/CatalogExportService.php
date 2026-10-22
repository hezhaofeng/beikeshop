<?php

namespace Plugin\CyberCloak\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class CatalogExportService
{
    /**
     * 把展示库（catalog_public）导出为 cyber-cloak:import-catalog 可直接导入的 JSON 文件。
     *
     * 用途：让 B 站（收款站）的商品库与 A 站 CyberCloak 展示库保持一致——B 站用
     * paypal-b:import-catalog 导入同一份文件后，SKU、商品 ID、名称和售价完全相同，
     * PayPal 映射商品明细里的 SKU 在 B 站目录里一定能命中。
     *
     * 导出保留稳定 ID，重复导出、重复导入均幂等；软删除的商品不导出。
     * 为避免大目录把整份数据放进内存，文件按块流式写出。
     *
     * @return array{brands:int,categories:int,products:int,skus:int}
     */
    public function export(string $file, string $connection = 'catalog_public', bool $activeOnly = false, int $chunk = 500): array
    {
        $directory = dirname($file);
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new InvalidArgumentException("导出目录不存在且无法创建：{$directory}");
        }

        $database = DB::connection($connection);
        $handle   = @fopen($file, 'wb');
        if ($handle === false) {
            throw new RuntimeException("导出文件无法写入：{$file}");
        }

        $count = ['brands' => 0, 'categories' => 0, 'products' => 0, 'skus' => 0];

        try {
            fwrite($handle, '{"brands":');
            fwrite($handle, $this->encode($this->brands($database, $activeOnly, $count)));
            fwrite($handle, ',"categories":');
            fwrite($handle, $this->encode($this->categories($database, $activeOnly, $count)));
            fwrite($handle, ',"products":[');

            $first = true;
            $query = $database->table('products')->whereNull('deleted_at')->orderBy('id');
            if ($activeOnly) {
                $query->where('active', true);
            }
            $query->chunkById($chunk, function ($products) use ($database, $handle, &$first, &$count): void {
                foreach ($this->products($database, $products, $count) as $product) {
                    fwrite($handle, ($first ? '' : ',') . $this->encode($product));
                    $first = false;
                }
            });

            fwrite($handle, ']}');
        } finally {
            fclose($handle);
        }

        return $count;
    }

    private function brands($database, bool $activeOnly, array &$count): array
    {
        $query = $database->table('brands')->orderBy('id');
        if ($activeOnly) {
            $query->where('active', true);
        }

        $rows = [];
        foreach ($query->get() as $brand) {
            $rows[] = [
                'id'         => (int) $brand->id,
                'name'       => (string) $brand->name,
                'first'      => (string) $brand->first,
                'logo'       => (string) $brand->logo,
                'sort_order' => (int) $brand->sort_order,
                'active'     => (bool) $brand->active,
            ];
        }
        $count['brands'] = count($rows);

        return $rows;
    }

    private function categories($database, bool $activeOnly, array &$count): array
    {
        $query = $database->table('categories')->orderBy('id');
        if ($activeOnly) {
            $query->where('active', true);
        }
        $categories   = $query->get();
        $descriptions = $this->descriptionsByOwner($database, 'category_descriptions', 'category_id', $categories->pluck('id')->all());

        $rows = [];
        foreach ($categories as $category) {
            $rows[] = [
                'id'           => (int) $category->id,
                'parent_id'    => (int) $category->parent_id,
                'position'     => (int) $category->position,
                'image'        => (string) $category->image,
                'active'       => (bool) $category->active,
                'descriptions' => $descriptions[(int) $category->id] ?? [],
            ];
        }
        $count['categories'] = count($rows);

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function products($database, $products, array &$count): array
    {
        $ids          = $products->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $descriptions = $this->descriptionsByOwner($database, 'product_descriptions', 'product_id', $ids);
        $categoryIds  = [];
        foreach ($database->table('product_categories')->whereIn('product_id', $ids)->get(['product_id', 'category_id']) as $relation) {
            $categoryIds[(int) $relation->product_id][] = (int) $relation->category_id;
        }
        $skus = [];
        foreach ($database->table('product_skus')->whereIn('product_id', $ids)->orderBy('id')->get() as $sku) {
            $skus[(int) $sku->product_id][] = [
                'id'           => (int) $sku->id,
                'variants'     => $this->decode($sku->variants),
                'position'     => (int) $sku->position,
                'images'       => $this->decode($sku->images),
                'model'        => (string) $sku->model,
                'sku'          => (string) $sku->sku,
                'price'        => (float) $sku->price,
                'origin_price' => (float) $sku->origin_price,
                'cost_price'   => (float) $sku->cost_price,
                'weight'       => (float) ($sku->weight ?? 0),
                'quantity'     => (int) $sku->quantity,
                'is_default'   => (bool) $sku->is_default,
                'active'       => (bool) $sku->active,
            ];
        }

        $rows = [];
        foreach ($products as $product) {
            $productId = (int) $product->id;
            $rows[]    = [
                'id'           => $productId,
                'brand_id'     => (int) $product->brand_id,
                'images'       => $this->decode($product->images),
                'price'        => (float) $product->price,
                'video'        => (string) $product->video,
                'position'     => (int) $product->position,
                'active'       => (bool) $product->active,
                'variables'    => $this->decode($product->variables),
                'tax_class_id' => (int) $product->tax_class_id,
                'weight'       => (float) $product->weight,
                'weight_class' => (string) $product->weight_class,
                'shipping'     => (bool) $product->shipping,
                'sales'        => (int) $product->sales,
                'descriptions' => $descriptions[$productId] ?? [],
                'category_ids' => $categoryIds[$productId] ?? [],
                'skus'         => $skus[$productId] ?? [],
            ];
            $count['products']++;
            $count['skus'] += count($skus[$productId] ?? []);
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, array<string, string>>>
     */
    private function descriptionsByOwner($database, string $table, string $ownerColumn, array $ownerIds): array
    {
        if ($ownerIds === []) {
            return [];
        }

        $result = [];
        foreach ($database->table($table)->whereIn($ownerColumn, $ownerIds)->get() as $description) {
            $result[(int) $description->{$ownerColumn}][(string) $description->locale] = [
                'name'             => (string) $description->name,
                'content'          => (string) ($description->content ?? ''),
                'meta_title'       => (string) $description->meta_title,
                'meta_description' => (string) $description->meta_description,
                'meta_keywords'    => (string) $description->meta_keywords,
            ];
        }

        return $result;
    }

    private function decode(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function encode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('导出数据无法编码为 JSON：' . json_last_error_msg());
        }

        return $json;
    }
}
