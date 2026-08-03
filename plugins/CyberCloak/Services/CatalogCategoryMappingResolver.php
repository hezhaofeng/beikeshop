<?php

namespace Plugin\CyberCloak\Services;

use InvalidArgumentException;

class CatalogCategoryMappingResolver
{
    /**
     * 为每个真实分类选择展示分类，允许多个真实分类指向同一个展示分类。
     *
     * $candidateCounts 的结构为：真实分类 ID => 展示分类 ID => 商品投票数。
     * 当前分类没有商品候选时，继承已经解析成功的父分类目标。
     *
     * @param array<int,array{id:int,parent_id:int}> $categories
     * @param array<int,array<int,int>>              $candidateCounts
     * @param array<int,int>                         $fallbackTargets
     * @param array<int,int>                         $requiredTargets
     * @return array<int,int|null>
     */
    public function resolve(array $categories, array $candidateCounts, array $fallbackTargets = [], array $requiredTargets = []): array
    {
        $parentById  = [];
        $categoryIds = [];
        foreach ($categories as $category) {
            $categoryId = (int) ($category['id'] ?? 0);
            if ($categoryId <= 0) {
                throw new InvalidArgumentException('分类映射包含无效的真实分类 ID');
            }

            $categoryIds[$categoryId] = true;
        }

        foreach ($categories as $category) {
            $categoryId = (int) $category['id'];
            $parentId   = (int) ($category['parent_id'] ?? 0);
            if ($parentId === $categoryId) {
                throw new InvalidArgumentException('分类映射检测到分类自引用');
            }

            // 父分类不在启用集合时按根分类处理，避免一个脏父级阻断整棵树。
            $parentById[$categoryId] = isset($categoryIds[$parentId]) ? $parentId : 0;
        }

        $depthById        = $this->depths($parentById);
        $directCandidates = $this->normalizeCandidates($candidateCounts);
        $aggregated       = $directCandidates;
        $fallbackTargets  = array_values(array_unique(array_filter(array_map('intval', $fallbackTargets), fn (int $id): bool => $id > 0)));
        $requiredTargets  = array_values(array_unique(array_filter(array_map('intval', $requiredTargets), fn (int $id): bool => $id > 0)));

        // 先从叶子向根汇总商品投票，让父分类也能继承子孙商品的展示分类。
        $bottomUp = array_keys($parentById);
        usort($bottomUp, function (int $left, int $right) use ($depthById): int {
            return [$depthById[$right], $right] <=> [$depthById[$left], $left];
        });
        foreach ($bottomUp as $categoryId) {
            $parentId = $parentById[$categoryId];
            if ($parentId === 0) {
                continue;
            }

            foreach ($aggregated[$categoryId] ?? [] as $publicId => $votes) {
                $aggregated[$parentId][$publicId] = ($aggregated[$parentId][$publicId] ?? 0) + $votes;
            }
        }

        // 再从根向叶子解析目标，保证无直接商品的子分类可以继承父级目标。
        $topDown = array_keys($parentById);
        usort($topDown, function (int $left, int $right) use ($depthById): int {
            return [$depthById[$left], $left] <=> [$depthById[$right], $right];
        });
        $resolved = [];
        foreach ($topDown as $categoryId) {
            $target = $this->bestCandidate($aggregated[$categoryId] ?? []);
            if ($target === null) {
                $parentId = $parentById[$categoryId];
                $target   = $parentId === 0
                    ? $this->fallbackTarget($categoryId, $fallbackTargets)
                    : ($resolved[$parentId] ?? null);
            }

            $resolved[$categoryId] = $target;
        }

        return $this->ensureRequiredTargetCoverage($resolved, $directCandidates, $requiredTargets);
    }

    /**
     * 为每个有已确认商品候选的展示分类至少保留一个真实分类 URL。
     *
     * 单纯按票数选择会让多个真实分类持续指向同一个展示分类，导致其他有商品的展示分类
     * 没有稳定 URL。这里优先复用兜底或重复目标的真实分类，避免挤掉唯一的已有入口。
     *
     * @param array<int,int|null>       $resolved
     * @param array<int,array<int,int>> $directCandidates
     * @param array<int,int>            $requiredTargets
     * @return array<int,int|null>
     */
    private function ensureRequiredTargetCoverage(array $resolved, array $directCandidates, array $requiredTargets): array
    {
        if ($requiredTargets === []) {
            return $resolved;
        }

        $requiredSet = array_fill_keys($requiredTargets, true);
        $coverage    = [];
        foreach ($resolved as $publicId) {
            $publicId = (int) $publicId;
            if ($publicId > 0) {
                $coverage[$publicId] = ($coverage[$publicId] ?? 0) + 1;
            }
        }

        $reservedCategories = [];
        foreach ($requiredTargets as $targetId) {
            if (($coverage[$targetId] ?? 0) > 0) {
                continue;
            }

            $bestCategoryId = null;
            $bestPriority   = PHP_INT_MAX;
            $bestVotes      = -1;
            foreach ($directCandidates as $categoryId => $candidates) {
                $categoryId = (int) $categoryId;
                if (! array_key_exists($categoryId, $resolved) || isset($reservedCategories[$categoryId])) {
                    continue;
                }

                $votes = (int) ($candidates[$targetId] ?? 0);
                if ($votes <= 0) {
                    continue;
                }

                $currentTarget = (int) ($resolved[$categoryId] ?? 0);
                // 不挪走另一个必需分类的唯一入口，避免覆盖修复本身产生新的缺口。
                if (isset($requiredSet[$currentTarget]) && ($coverage[$currentTarget] ?? 0) <= 1) {
                    continue;
                }

                // 先使用非必需兜底目标，其次才复用已有必需分类的重复入口。
                $priority = isset($requiredSet[$currentTarget]) ? 1 : 0;
                if ($priority < $bestPriority
                    || ($priority === $bestPriority && $votes > $bestVotes)
                    || ($priority === $bestPriority && $votes === $bestVotes && ($bestCategoryId === null || $categoryId < $bestCategoryId))) {
                    $bestCategoryId = $categoryId;
                    $bestPriority   = $priority;
                    $bestVotes      = $votes;
                }
            }

            if ($bestCategoryId === null) {
                continue;
            }

            $previousTarget = (int) ($resolved[$bestCategoryId] ?? 0);
            if ($previousTarget > 0) {
                $coverage[$previousTarget]--;
            }
            $resolved[$bestCategoryId]           = $targetId;
            $coverage[$targetId]                 = ($coverage[$targetId] ?? 0) + 1;
            $reservedCategories[$bestCategoryId] = true;
        }

        return $resolved;
    }

    /**
     * 为没有商品候选的根分类选择稳定的展示分类，避免每次重建结果漂移。
     *
     * @param array<int,int> $fallbackTargets
     */
    private function fallbackTarget(int $categoryId, array $fallbackTargets): ?int
    {
        if ($fallbackTargets === []) {
            return null;
        }

        $seed = hexdec(substr(hash('sha256', "cyber-cloak:category:{$categoryId}"), 0, 12));

        return $fallbackTargets[$seed % count($fallbackTargets)] ?? null;
    }

    /**
     * 计算分类深度并检测父级循环。
     *
     * @param array<int,int> $parentById
     * @return array<int,int>
     */
    private function depths(array $parentById): array
    {
        $depths = [];
        foreach (array_keys($parentById) as $categoryId) {
            $visited = [];
            $current = $categoryId;
            $depth   = 0;
            while (($parentId = $parentById[$current] ?? 0) !== 0) {
                if (isset($visited[$current])) {
                    throw new InvalidArgumentException('分类映射检测到父级循环');
                }

                $visited[$current]  = true;
                $current            = $parentId;
                $depth++;
            }

            $depths[$categoryId] = $depth;
        }

        return $depths;
    }

    /**
     * 清理商品投票输入，避免无效展示分类影响排序结果。
     *
     * @param array<int,array<int,int|float|string>> $candidateCounts
     * @return array<int,array<int,int>>
     */
    private function normalizeCandidates(array $candidateCounts): array
    {
        $normalized = [];
        foreach ($candidateCounts as $categoryId => $candidates) {
            foreach ($candidates as $publicId => $votes) {
                $publicId = (int) $publicId;
                $votes    = (int) $votes;
                if ($publicId > 0 && $votes > 0) {
                    $normalized[(int) $categoryId][$publicId] = $votes;
                }
            }
        }

        return $normalized;
    }

    /**
     * 按商品投票数选择展示分类，平票时使用较小 ID 保证重建结果稳定。
     *
     * @param array<int,int> $candidates
     */
    private function bestCandidate(array $candidates): ?int
    {
        $bestId    = null;
        $bestVotes = -1;
        foreach ($candidates as $publicId => $votes) {
            $publicId = (int) $publicId;
            $votes    = (int) $votes;
            if ($votes > $bestVotes || ($votes === $bestVotes && ($bestId === null || $publicId < $bestId))) {
                $bestId    = $publicId;
                $bestVotes = $votes;
            }
        }

        return $bestId;
    }
}
