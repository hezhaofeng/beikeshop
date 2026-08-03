<?php

namespace Tests\Unit\CyberCloak;

use Tests\TestCase;

class StorefrontTemplateTest extends TestCase
{
    /**
     * 父分类没有直属商品时，子分类仍应在商品空状态之前渲染。
     */
    public function test_category_children_are_not_gated_by_product_count(): void
    {
        $templatePath = base_path('themes/default/category.blade.php');
        $template     = file_get_contents($templatePath);
        $childrenAt   = strpos($template, '@if ($children)');
        $productsAt   = strpos($template, '@if (count($products_format))');

        $this->assertFileExists($templatePath);
        $this->assertNotFalse($childrenAt);
        $this->assertNotFalse($productsAt);
        $this->assertLessThan($productsAt, $childrenAt);
    }
}
