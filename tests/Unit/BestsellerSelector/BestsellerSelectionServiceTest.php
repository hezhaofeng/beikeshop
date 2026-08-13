<?php

namespace Tests\Unit\BestsellerSelector;

use Plugin\BestsellerSelector\Services\BestsellerSelectionService;
use Tests\TestCase;

class BestsellerSelectionServiceTest extends TestCase
{
    /**
     * 勾选集合应过滤非法值和重复值，并保持用户最早勾选的顺序。
     */
    public function test_normalize_ids_keeps_first_selected_order(): void
    {
        $service = new BestsellerSelectionService;

        $this->assertSame([12, 8, 5], $service->normalizeIds([
            12,
            '8',
            ['id' => 12],
            ['id' => 5],
            0,
            -1,
            ['name' => 'no id'],
        ]));
    }

    /**
     * 未配置或非法模式都应保持原有销量排序，避免首页热卖区被意外覆盖。
     */
    public function test_invalid_mode_falls_back_to_automatic_mode(): void
    {
        config()->set('bk.plugin.bestseller_selector.mode', 'invalid');
        $service = new BestsellerSelectionService;

        $this->assertSame('auto', $service->mode());
        $this->assertSame(['products' => ['id' => 99]], $service->applyHomepageSelection([
            'products' => ['id' => 99],
        ]));
    }

    /**
     * 手动模式读取的历史配置也应清理重复和无效 ID，兼容设置表中的 JSON 数组。
     */
    public function test_selected_ids_are_normalized_from_plugin_settings(): void
    {
        config()->set('bk.plugin.bestseller_selector.mode', 'manual');
        config()->set('bk.plugin.bestseller_selector.product_ids', [33, '12', 33, 0]);
        $service = new BestsellerSelectionService;

        $this->assertSame('manual', $service->mode());
        $this->assertSame([33, 12], $service->selectedIds());
    }
}
