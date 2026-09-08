<?php

namespace Plugin\Meilisearch\Services;

use Plugin\CyberCloak\Services\SkuMappingService;
use Plugin\CyberCloak\Services\StoreContext;
use RuntimeException;

/**
 * Meilisearch 与 CyberCloak 的兼容层。
 *
 * CyberCloak 把商品拆成真实库和展示库两个连接，同一个商品在两个库里是互不相干的自增 ID。
 * 索引必须按商品库分开建立，检索时也只能读取当前请求所处商品库的索引，否则会用真实库 ID
 * 去展示库取商品，返回完全不相干的结果。CyberCloak 未安装时这里退化为单一 real 商品库。
 */
class CatalogContext
{
    public const REAL = 'real';

    public const PUBLIC = 'public';

    /**
     * 判断 CyberCloak 是否已随应用加载；未安装时 Meilisearch 仍可独立工作。
     */
    public static function cyberCloakAvailable(): bool
    {
        return class_exists(StoreContext::class) && app()->bound(StoreContext::class);
    }

    /**
     * 返回需要建立索引的商品库列表。
     *
     * @return array<int,string>
     */
    public static function catalogs(): array
    {
        if (! self::cyberCloakAvailable()) {
            return [self::REAL];
        }

        // 展示库连接未配置时只索引真实库，避免为不存在的连接创建空索引。
        return self::connectionConfigured(self::PUBLIC)
            ? [self::REAL, self::PUBLIC]
            : [self::REAL];
    }

    /**
     * 返回当前请求应当检索的商品库。
     */
    public static function current(): string
    {
        if (! self::cyberCloakAvailable()) {
            return self::REAL;
        }

        $context = app(StoreContext::class);

        // 上下文未激活时说明不是前台商品请求，沿用真实库，与 UsesCatalogConnection 保持一致。
        return $context->isActive() && $context->isPublic() ? self::PUBLIC : self::REAL;
    }

    /**
     * 获取商品库对应的数据库连接名。
     */
    public static function connection(string $catalog): string
    {
        self::assertCatalog($catalog);

        if ($catalog === self::REAL) {
            return (string) config('cyber_cloak.connections.real', config('database.default', 'mysql'));
        }

        return (string) config('cyber_cloak.connections.public', 'catalog_public');
    }

    /**
     * 在指定商品库上下文中执行回调。
     *
     * 索引读取商品要经过 Product 等模型的 UsesCatalogConnection，只有激活 StoreContext 才能让
     * 模型、关联和 ProductRepo 的可见性判断统一落到目标连接上。
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function runAs(string $catalog, callable $callback): mixed
    {
        self::assertCatalog($catalog);

        if (! self::cyberCloakAvailable() || $catalog === self::REAL) {
            return $callback();
        }

        $context = app(StoreContext::class);
        if ($context->isActive()) {
            // 前台请求已经绑定了商品库，重建索引会污染当前请求上下文，必须放到队列或命令行执行。
            throw new RuntimeException('不能在已激活商品库上下文的请求中构建索引');
        }

        $context->activate([
            'mode'   => StoreContext::PUBLIC,
            'reason' => 'meilisearch-index',
        ]);

        try {
            return $callback();
        } finally {
            $context->reset();
        }
    }

    /**
     * 判断指定商品库是否需要遵守 CyberCloak 的已发布 SKU 白名单。
     */
    public static function constrained(string $catalog): bool
    {
        return $catalog === self::PUBLIC && self::cyberCloakAvailable();
    }

    /**
     * 把展示 SKU 查询限制在当前已发布映射内；真实库或未安装 CyberCloak 时不做限制。
     */
    public static function constrainSkus(string $catalog, mixed $query, string $skuTable = 'product_skus'): void
    {
        if (! self::constrained($catalog)) {
            return;
        }

        app(SkuMappingService::class)->constrainToPublishedPublicSkus($query, $skuTable);
    }

    /**
     * 校验商品库标识，避免拼接出非法索引名或连接名。
     */
    public static function assertCatalog(string $catalog): void
    {
        if (! in_array($catalog, [self::REAL, self::PUBLIC], true)) {
            throw new RuntimeException("商品库标识无效：{$catalog}");
        }
    }

    /**
     * 判断商品库连接是否已在 database.connections 中定义。
     */
    private static function connectionConfigured(string $catalog): bool
    {
        $connection = self::connection($catalog);

        return $connection !== '' && config("database.connections.{$connection}") !== null;
    }
}
