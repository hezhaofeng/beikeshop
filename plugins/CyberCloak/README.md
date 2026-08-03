# cyberCloak

本插件是同域双商品库方案的阶段一至阶段五实现。它根据请求中的有效访问 key、签名 Cookie 和 IP 访问规则建立 `real` 或 `public` 的 `StoreContext`，并驱动前台商品浏览、展示库导入、商品/SKU 映射、稳定数字 URL、购物车来源隔离和订单履约快照。

## 阶段一已实现内容

- 新增 `catalog_public` 数据库连接；
- 无凭据请求默认进入 `public` 展示模式；
- 有效 query key 首次请求直接返回原页面并签发 `HttpOnly`、`Secure`、`SameSite=Lax` Cookie；
- Cookie 只保存 key 标识、有效期、随机数和 HMAC 签名，不保存明文 key；
- key 支持启用、禁用、删除和过期时间，服务端只保存 SHA-256 摘要；
- 支持 IPv4、IPv6、CIDR 白名单和黑名单，黑名单优先级最高；
- 支持在可信代理配置下读取 Cloudflare `CF-Connecting-IP`；
- query 中的 `key` 会在进入控制器前移除，分页和站内链接不会继续携带该参数；
- 前台 HTML 的 `shop` 组和前台 API 的 `api` 组都在路由模型绑定前解析上下文；
- Horizon 使用独立中间件组，不执行前台商品上下文解析；
- 请求结束后清理上下文，并通过 `Cache-Control: private, no-store` 防止共享缓存串模式。

## 配置

复制 `.env.example` 中的 `CATALOG_PUBLIC_DB_*` 参数后，按实际展示商品库配置连接。阶段一不会自动切换应用默认连接，后续商品仓储应通过：

```php
$connection = app(\Plugin\CyberCloak\Services\StoreContext::class)->connectionName();
```

显式选择 `mysql` 或 `catalog_public`。

## 阶段二：商品库和 SKU 映射

启用插件迁移后，迁移会在 `catalog_public` 连接创建商品、分类、`category_paths`、品牌、描述和 SKU 表，并在主库创建映射版本、商品映射和 SKU 映射表。主库和展示库通过连接名隔离，不会切换 Laravel 默认连接。

展示库导入使用 JSON 文件，最小格式如下：

```json
{
  "brands": [{"id": 1, "name": "示例品牌"}],
  "categories": [{"id": 1, "descriptions": {"zh_cn": {"name": "示例分类"}}}],
  "products": [{
    "id": 7,
    "brand_id": 1,
    "category_ids": [1],
    "descriptions": {"zh_cn": {"name": "展示商品", "content": "商品描述"}},
    "skus": [{"id": 70, "sku": "PUBLIC-001", "model": "MODEL-001", "quantity": 10, "active": true}]
  }]
}
```

分类同步完成后，插件会根据 `categories.parent_id` 自动重建 `category_paths`，路径按根分类到当前分类写入，爬虫不需要额外提供路径数组。完整同步使用 `--truncate` 时会先清理旧路径；增量同步会重建展示库现有分类的全部路径。

导入与重建命令：

```bash
php artisan cyber-cloak:import-catalog storage/app/catalog.json --dry-run
php artisan cyber-cloak:import-catalog storage/app/catalog.json
php artisan cyber-cloak:rebuild-sku-mappings --mode=exact
php artisan cyber-cloak:rebuild-sku-mappings --mode=candidate
php artisan cyber-cloak:rebuild-sku-mappings --mode=random --publish
php artisan cyber-cloak:confirm-sku-mapping VERSION REAL_SKU_ID PUBLIC_SKU_ID --fulfillment-sku=FULFILLMENT_SKU
```

精确匹配按 SKU 加规格、SKU、型号加规格、型号依次查找。唯一候选才会标记为 `confirmed`；没有候选为 `pending`，多个候选为 `conflict`。候选模式可以按同名商品生成待人工确认数据，但不会直接确认或用于下单。

推荐使用后台插件编辑页中的“商品映射与首页链接”面板完成日常操作。面板提供四个按钮：

- “商品自动映射”：全量扫描真实库 SKU；无待确认项时自动发布版本并重建商品链接；处理异常 SKU 后再次点击可发布草稿；
- “分类链接同步”：使用当前已发布版本重建分类稳定链接，首页导航和分类 Banner 共用这套路由；
- “Banner 链接检查”：扫描 `base.design_setting` 中的首页模块链接，统计商品、分类、自定义链接及未映射项，不复制第二套装修配置；
- “清除缓存”：清理应用、配置、视图和优化缓存，使最新首页装修与映射立即生效。

首页装修继续只维护真实库的商品和分类 ID。新增幻灯片、选项卡或其他模块时，只要链接沿用装修器的 `{type, value}` 结构，Banner 检查会自动纳入该模块。

`random` 模式用于两边商品不是同一批数据、但需要先固定展示商品的测试场景。它按真实商品选择一个 Cloak 商品，同一真实商品的多个 SKU 映射到该 Cloak 商品下的启用 SKU，`fulfillment_sku` 仍保存真实 SKU。首次生成后，后续随机重建会优先读取最近随机版本中的商品和 SKU 映射；只有新增真实商品或原 Cloak 商品/SKU 已失效时才重新选择。因此固定关系来源是映射表，不是请求参数或内存缓存。

发布后重建稳定商品 URL：

```bash
php artisan cyber-cloak:rebuild-route-mappings --type=product --mapping-version=VERSION
```

该命令会把已发布版本的真实商品 ID、Cloak 商品 ID 和对外 `url_id` 写入 `catalog_route_maps`。之后访问真实商品 URL 时，路由表继续使用这组已保存关系。

分类 URL 映射使用已发布商品映射和两边 `product_categories` 的关系推导展示分类：真实分类会汇总整个子树的商品投票，票数最高的展示分类作为目标，因此允许多个真实分类映射到同一个 Cloak 分类。没有直接商品候选的子分类继承父分类目标；没有候选的根分类按稳定哈希分配到已有商品映射的 Cloak 分类，重复重建不会漂移。重建还会为每个实际覆盖的 Cloak 分类保留至少一条真实分类路由，避免多数投票让有商品的分类失去导航入口；没有已确认商品映射的分类不会进入前台导航。分类路由默认要求每个真实分类都有 `public_record_id`，`--allow-unmapped` 仅用于排查数据。

只有 `pending` 和 `conflict` 均已处理后，才使用 `--publish` 发布映射版本；测试阶段可以显式增加 `--force`。

插件后台的“访问 key 列表”使用 JSON 数组。推荐由服务层创建摘要记录，记录形如：

```json
[
  {
    "id": "KEY_ID",
    "hash": "SHA256_OF_KEY",
    "status": "enabled",
    "expires_at": null
  }
]
```

`expires_at` 为 `null` 或 `0` 时表示永久有效。IP 列表支持每行一个地址，也支持逗号、分号分隔。

## 启用与验证

1. 将 `cyberCloak` 插件安装并启用；
2. 配置 `catalog_public` 连接和 key/IP 设置；
3. 执行 `php artisan optimize:clear`；
4. 使用有效 key 访问任意前台页面，确认响应包含 `Set-Cookie` 且没有 `Location`；
5. 后续请求携带 Cookie 时检查 `StoreContext::isReal()`；
6. 使用无 Cookie、失效 key、过期 key、黑名单 IP 分别确认回到 `public` 模式。

## 阶段三：前台商品浏览

- 商品、SKU、描述、分类和品牌模型在前台上下文激活期间使用对应商品库连接。
- 首页商品模块、分类、品牌、搜索、自动完成和商品详情沿用当前 `StoreContext`。
- 商品和分类数字 URL使用 `catalog_route_maps` 的 `url_id`，展示商品映射优先读取已发布映射版本。
- 使用 `cyber-cloak:rebuild-route-mappings --type=product|category` 生成路由映射；路由表存在但缺少活动映射时返回 404，不回退展示库主键。
- 展示库未建立主库属性、关系和 `category_paths` 表时，前台查询会自动使用展示库直接关系，后台继续使用主库完整关系。
- 商品、分类和品牌缓存包含商品库模式，避免真实模式和展示模式共用缓存结果。

## 阶段四：购物车和收藏

- 主库 `cart_products` 保存 `catalog_mode`、`catalog_product_id`、`catalog_sku_id` 和 `fulfillment_sku`。
- 主库 `customer_wishlists` 保存 `catalog_mode` 和 `catalog_product_id`。
- 购物车读取、数量更新、访客购物车合并和收藏列表按明细来源显式读取真实库或 `catalog_public`。
- 真实商品与展示商品可保存来源隔离的购物车明细；展示购物车在阶段五订单快照和履约库存校验后进入结算流程。
- 未发布或未确认的展示 SKU不会进入可售列表和购物车；展示购物车在阶段五订单快照和履约库存校验后进入结算。

## 阶段五：订单和履约库存

- `order_products` 保存商品库模式、库内商品/SKU ID、履约 SKU、映射版本和下单快照。
- 创建订单前重新校验履约库存，支付成功按订单快照扣减库存，退款恢复库存。
- 真实库和展示库的库存连接由订单快照显式决定，后台或支付回调不会读取前台 `StoreContext`。
- 后台订单列表和管理 API 支持按商品库模式、履约 SKU和映射版本筛选。
- 支付回调、Webhook、后台和队列任务不加入前台上下文中间件；具体支付插件仍需在沙箱中验证其状态机调用。

## 阶段六：后台管理和供应商 IP 同步

- 插件配置页支持供应商适配器、地址、超时、缓存 TTL、同步周期和失败策略。
- `cyber-cloak:test-ip-provider` 只测试和标准化响应；`cyber-cloak:sync-ip-provider` 原子替换本地缓存，`--dry-run` 不写缓存和日志。
- 供应商请求只在后台测试、手动命令或定时调度中发生，前台只读取 `cyber_cloak_ip_provider_entries`。
- 后台 API 提供 key 脱敏列表、创建/启用/禁用/删除、供应商测试/同步和展示订单审核状态更新。

## 阶段七：四层流量漏斗

前台会先执行现有 IP 黑白名单硬规则，再校验 query key 和签名 Cookie；有效凭据直接进入真实站。只有没有有效凭据的请求才会执行四层漏斗，静态/供应商黑名单仍然优先于 key。

1. 急速拦截：本地静态 IP/CIDR 黑名单、国家信号和基础 UA 校验；高置信度黑名单直接阻断，国家和 UA 作为可解释风险分。明确阻断国家进入挑战分支，GeoLite 无法识别时保持 unknown。
2. 核心风控：本地 MaxMind MMDB 的 Country/ASN 信息、匿名 IP 数据（若配置）、IP 信誉网段和 ASN 规则。GeoLite2 不等于 IP 信誉或住宅代理数据，后两者通过信誉网段、现有供应商适配器或其他信号补充。
3. 规则匹配：可选 Laravel `RateLimiter`，按 IP 与路由计数；频控结果返回 `rate_limit` 动作，不在请求中访问供应商。
4. 深度识别：自动化 UA/请求头线索和可选指纹 Cookie 只作为风险信号；浏览器端指纹脚本应按登录、注册、支付和下单路由单独接入。

国家白名单、浏览器语言白名单和 UA 黑名单属于无凭据流量的漏斗信号，在 key/Cookie 校验失败后执行；有效 key 或签名 Cookie 不受这些信号阻断。浏览器语言读取 `Accept-Language`，支持 `zh` 与 `zh-CN` 的基础语言兼容匹配；UA 黑名单使用大小写不敏感关键词匹配。

漏斗结果包含 `action`、`stage`、`score`、`reasons` 和 `signals`。`block`、`challenge`、`rate_limit` 的无 key 请求进入展示模式并保留原因，便于先使用 Shadow Mode 观察误杀，再逐步调高强度。MaxMind 数据库通过 `CYBER_CLOAK_GEOIP_*_DATABASE` 配置本地路径，前台不在请求路径访问远程 API。
- 展示订单创建时写入 `pending` 审核状态；审核为 `approved` 后再进入正常履约处理。
