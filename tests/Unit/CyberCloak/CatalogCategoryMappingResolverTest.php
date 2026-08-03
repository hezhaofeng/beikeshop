<?php

namespace Tests\Unit\CyberCloak;

use InvalidArgumentException;
use Plugin\CyberCloak\Services\CatalogCategoryMappingResolver;
use Tests\TestCase;

class CatalogCategoryMappingResolverTest extends TestCase
{
    /**
     * 多个真实分类可以稳定复用同一个展示分类。
     */
    public function test_multiple_real_categories_share_public_target(): void
    {
        $targets = (new CatalogCategoryMappingResolver)->resolve([
            ['id' => 1, 'parent_id' => 0],
            ['id' => 2, 'parent_id' => 1],
            ['id' => 3, 'parent_id' => 1],
        ], [
            2 => [7 => 2],
            3 => [7 => 1],
        ], [8, 9]);

        $this->assertSame(7, $targets[1]);
        $this->assertSame(7, $targets[2]);
        $this->assertSame(7, $targets[3]);
    }

    /**
     * 没有直接商品候选的子分类继承父分类目标。
     */
    public function test_empty_child_inherits_parent_target(): void
    {
        $targets = (new CatalogCategoryMappingResolver)->resolve([
            ['id' => 10, 'parent_id' => 0],
            ['id' => 11, 'parent_id' => 10],
            ['id' => 12, 'parent_id' => 11],
        ], [
            10 => [3 => 4],
        ], [4, 5]);

        $this->assertSame(3, $targets[10]);
        $this->assertSame(3, $targets[11]);
        $this->assertSame(3, $targets[12]);
    }

    /**
     * 根分类没有商品候选时使用稳定兜底，不因重建顺序变化。
     */
    public function test_empty_root_uses_stable_fallback_target(): void
    {
        $resolver = new CatalogCategoryMappingResolver;
        $first    = $resolver->resolve([
            ['id' => 20, 'parent_id' => 0],
        ], [], [4, 5]);
        $second = $resolver->resolve([
            ['id' => 20, 'parent_id' => 0],
        ], [], [4, 5]);

        $this->assertSame($first, $second);
        $this->assertContains($first[20], [4, 5]);
    }

    /**
     * 平票时选择较小展示分类 ID，保证结果可复现。
     */
    public function test_candidate_tie_uses_lower_public_id(): void
    {
        $targets = (new CatalogCategoryMappingResolver)->resolve([
            ['id' => 30, 'parent_id' => 0],
        ], [
            30 => [9 => 1, 8 => 1],
        ], [4, 5]);

        $this->assertSame(8, $targets[30]);
    }

    /**
     * 已确认商品实际覆盖的展示分类都应保留稳定 URL，避免多数投票挤掉有商品的分类入口。
     */
    public function test_required_targets_keep_at_least_one_real_category_route(): void
    {
        $targets = (new CatalogCategoryMappingResolver)->resolve([
            ['id' => 1, 'parent_id' => 0],
            ['id' => 2, 'parent_id' => 0],
            ['id' => 3, 'parent_id' => 0],
            ['id' => 4, 'parent_id' => 0],
            ['id' => 5, 'parent_id' => 0],
            ['id' => 6, 'parent_id' => 0],
            ['id' => 7, 'parent_id' => 0],
            ['id' => 8, 'parent_id' => 0],
            ['id' => 9, 'parent_id' => 0],
        ], [
            1 => [1 => 10, 5 => 1],
            2 => [1 => 9, 6 => 1],
            3 => [2 => 10, 9 => 1],
            4 => [3 => 10, 10 => 1],
            5 => [4 => 10, 11 => 1],
            6 => [1 => 8],
            7 => [2 => 8],
            8 => [3 => 8],
            9 => [4 => 8],
        ], [1, 2, 3, 4, 5, 6, 9, 10, 11], [1, 2, 3, 4, 5, 6, 9, 10, 11]);

        $resolvedTargets = array_values(array_unique(array_values($targets)));
        sort($resolvedTargets, SORT_NUMERIC);

        $this->assertSame([1, 2, 3, 4, 5, 6, 9, 10, 11], $resolvedTargets);
    }

    /**
     * 父级循环必须立即报错，避免分类映射进入无限解析。
     */
    public function test_parent_cycle_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CatalogCategoryMappingResolver)->resolve([
            ['id' => 40, 'parent_id' => 41],
            ['id' => 41, 'parent_id' => 40],
        ], [], [4]);
    }
}
