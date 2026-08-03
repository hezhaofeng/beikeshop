<?php

namespace Tests\Unit\CyberCloak;

use InvalidArgumentException;
use Plugin\CyberCloak\Services\CatalogImportService;
use Tests\TestCase;

class CatalogImportTest extends TestCase
{
    /**
     * 分类路径按根到当前分类生成，根分类自身层级为 0。
     */
    public function test_category_path_rows_follow_parent_chain(): void
    {
        $service = new CatalogImportService;
        $method  = (new \ReflectionClass($service))->getMethod('buildCategoryPathRows');
        $rows    = $method->invoke($service, [
            ['id' => 30, 'parent_id' => 20],
            ['id' => 10, 'parent_id' => 0],
            ['id' => 20, 'parent_id' => 10],
        ]);

        $this->assertSame([
            ['category_id' => 30, 'path_id' => 10, 'level' => 0],
            ['category_id' => 30, 'path_id' => 20, 'level' => 1],
            ['category_id' => 30, 'path_id' => 30, 'level' => 2],
            ['category_id' => 10, 'path_id' => 10, 'level' => 0],
            ['category_id' => 20, 'path_id' => 10, 'level' => 0],
            ['category_id' => 20, 'path_id' => 20, 'level' => 1],
        ], $rows);
    }

    /**
     * 循环父级会被识别，避免同步生成无限路径。
     */
    public function test_category_path_rows_reject_parent_cycle(): void
    {
        $service = new CatalogImportService;
        $method  = (new \ReflectionClass($service))->getMethod('buildCategoryPathRows');

        $this->expectException(InvalidArgumentException::class);
        $method->invoke($service, [
            ['id' => 1, 'parent_id' => 2],
            ['id' => 2, 'parent_id' => 1],
        ]);
    }

    /**
     * 找不到父分类时保留当前分类自身路径，与主库修复规则保持一致。
     */
    public function test_category_path_rows_treat_missing_parent_as_root(): void
    {
        $service = new CatalogImportService;
        $method  = (new \ReflectionClass($service))->getMethod('buildCategoryPathRows');

        $rows = $method->invoke($service, [
            ['id' => 8, 'parent_id' => 999],
        ]);

        $this->assertSame([
            ['category_id' => 8, 'path_id' => 8, 'level' => 0],
        ], $rows);
    }

    /**
     * 试运行也必须拒绝没有稳定身份的 SKU，不能把随机值带入正式导入。
     */
    public function test_dry_run_rejects_sku_without_stable_identity(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cyber-cloak-catalog-');
        file_put_contents($file, json_encode([
            'products' => [[
                'skus' => [['price' => 10]],
            ]],
        ], JSON_UNESCAPED_UNICODE));

        try {
            $this->expectException(InvalidArgumentException::class);
            (new CatalogImportService)->import($file, 'catalog_public', false, true);
        } finally {
            @unlink($file);
        }
    }

    /**
     * 只有稳定 id 的历史 SKU 使用可重复的占位身份并通过试运行。
     */
    public function test_dry_run_accepts_sku_with_stable_id(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cyber-cloak-catalog-');
        file_put_contents($file, json_encode([
            'products' => [[
                'skus' => [['id' => 12]],
            ]],
        ], JSON_UNESCAPED_UNICODE));

        try {
            $result = (new CatalogImportService)->import($file, 'catalog_public', false, true);

            $this->assertSame(1, $result['skus']);
        } finally {
            @unlink($file);
        }
    }
}
