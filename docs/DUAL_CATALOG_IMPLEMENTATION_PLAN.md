# cyberCloak 插件：同域双商品库实现开发计划

> 当前代码状态：阶段一至阶段五链路已实现，阶段六已补齐 key 管理 API、供应商本地缓存/同步命令/定时调度、后台同步入口和展示订单审核字段；支付厂商回调与真实数据库仍需在沙箱环境联调。

## 1. 目标与范围

本方案用于在同一个域名、同一套 URL 下，根据请求上下文展示真实商品库或展示商品库。两种模式都保留商品浏览、购物车、收藏、结算、订单和发货流程。

最终采用“主运营库 + 展示商品库 + SKU 固定映射”的架构：

- 主库保存客户、Session、购物车、收藏、订单、支付、地址和售后数据。
- 真实商品使用主库商品表。
- 展示商品使用独立商品库，规模约 300 个商品。
- 真实 SKU 可以映射到展示 SKU，映射允许多对一。
- 订单同时保存展示 SKU、履约 SKU 和下单快照。
- 商品转换只改变当前请求返回的数据，不做商品 URL 跳转。

访问模式判断建议固定为：

```text
有效 key 或有效 Cookie
且通过 IP 白名单校验
且没有命中 IP 黑名单
    => 真实模式

其他情况
    => 展示模式
```

黑名单优先级高于 key 和 Cookie。首次请求使用有效 key时当前请求立即进入真实模式，并通过响应签发 Cookie；后续请求由 Cookie维持模式。白名单为空时表示不启用白名单限制。

## 2. 当前项目切入点

| 模块 | 当前位置 | 改造目的 |
|---|---|---|
| 插件启动 | `beike/Shop/Providers/PluginServiceProvider.php` | 加载插件 Bootstrap、路由和中间件 |
| 前台中间件 | `app/Http/Kernel.php` | 在路由模型绑定前建立商品模式 |
| 商品查询 | `beike/Repositories/ProductRepo.php` | 统一从当前商品库读取商品、SKU和关系 |
| 商品详情 | `beike/Shop/Http/Controllers/ProductController.php` | 使用展示商品资源生成详情 |
| 分类列表 | `beike/Shop/Http/Controllers/CategoryController.php` | 读取当前商品库分类和商品 |
| 首页 | `beike/Shop/Http/Controllers/HomeController.php` | 替换首页分类和商品模块 |
| 购物车 | `beike/Shop/Services/CartService.php` | 保存商品库模式和 SKU 来源 |
| 订单商品 | `beike/Repositories/OrderProductRepo.php` | 保存展示 SKU、履约 SKU和商品快照 |
| 库存状态机 | `beike/Services/StateMachineService.php` | 按履约 SKU扣减库存和恢复库存 |
| 商品 URL | `beike/Models/Product.php` | 保持同一个 URL，不使用展示商品 ID生成新链接 |

现有插件中间件默认追加到 `shop` 组末尾。商品模式中间件必须位于 `SubstituteBindings` 之前，否则隐式路由绑定可能已经从错误数据库加载商品。

## 3. 插件目录

```text
plugins/CyberCloak/
├── Bootstrap.php
├── config.json
├── Config/
│   └── cyber_cloak.php
├── Console/
│   ├── ImportCatalog.php
│   ├── RebuildSkuMappings.php
│   └── SyncIpBlacklist.php
├── Middleware/Shop/
│   └── ResolveStoreContext.php
├── Middleware/API/
│   └── ResolveStoreContext.php
├── Models/
│   ├── AccessKey.php
│   ├── CatalogProductMapping.php
│   └── CatalogSkuMapping.php
├── Services/
│   ├── AccessKeyService.php
│   ├── CatalogResolver.php
│   ├── StoreContext.php
│   ├── SkuMappingService.php
│   ├── IpBlacklistService.php
│   └── PaymentOrderRouteService.php
├── Repositories/
│   ├── AccessKeyRepo.php
│   ├── CatalogProductRepo.php
│   └── CatalogSkuRepo.php
├── Admin/
│   ├── Controllers/
│   ├── Requests/
│   └── Views/
├── Migrations/
└── Lang/
```

## 4. 开发阶段

### 阶段一：基础配置和请求上下文（当前实现）

交付内容：

- 增加 `catalog_public` 数据库连接。
- 增加 `StoreContext`，统一表示 `real` 或 `public`。
- 增加 key 列表、状态和过期时间管理。
- 增加 Cookie 票据签发、验证、续期和失效处理。
- 增加 IP 白名单、黑名单和 Cloudflare 真实 IP解析。
- 在路由模型绑定前设置请求模式。
- 将同一上下文应用到前台 HTML 和前台 API；后台、支付回调、Webhook 和队列任务使用明确的固定连接。
- 请求结束时恢复数据库和上下文状态。

当前阶段明确不包含商品、SKU、购物车、订单和支付流程的切库改造；现有 `ProductRepo`、商品模型及订单流程仍使用主应用默认连接。Horizon 使用独立的 `horizon` 中间件组，不继承 `shop` 组动态追加的前台商品上下文，避免队列后台执行 key/IP 判定或写入 `beike_context`。

Cookie 设计要求：

- Cookie 保存签名票据，不保存明文 key。
- key 服务端过期时间为 `0` 时表示永久有效。
- Cookie 使用 `HttpOnly`、`Secure` 和 `SameSite=Lax`。
- 默认 Cookie 名称为 `beike_context`，可通过 `CYBER_CLOAK_COOKIE_NAME` 自定义。
- 默认 query key 参数名为 `key`，可通过 `CYBER_CLOAK_KEY_PARAMETER` 自定义。
- 有效 query key 首次访问直接返回页面并下发 Cookie，不生成 `Location` 响应头。
- 从请求 query 中移除 `key`，避免分页和站内链接继续携带敏感参数。

### 阶段二：商品库与 SKU 映射（基础设施已实现）

已交付内容：

- 建立展示商品库连接和数据导入命令。
- 建立商品级和 SKU 级映射表。
- 支持真实 SKU 到展示 SKU 的多对一映射。
- 提供精确匹配自动生成和冲突人工确认。
- 提供映射版本、状态和批量重建能力。
- 建立 `CatalogResolver`，商品查询显式选择当前商品库。

实现文件：

- `plugins/CyberCloak/Migrations/2026_07_30_000001_create_catalog_tables.php`
- `plugins/CyberCloak/Services/CatalogImportService.php`
- `plugins/CyberCloak/Services/SkuMappingService.php`
- `plugins/CyberCloak/Console/ImportCatalog.php`
- `plugins/CyberCloak/Console/RebuildSkuMappings.php`

当前阶段不改造前台商品控制器，商品查询切库需要在阶段三接入上述显式查询入口。

当前实现的自动匹配顺序：

1. SKU 加规格组合精确匹配。
2. SKU 精确匹配。
3. 供应商型号加规格组合精确匹配。
4. 供应商型号精确匹配。
5. 同名商品仅生成待确认候选。

EAN、UPC、外部商品编码和 slug 需要在后续导入字段扩展后接入；当前数据结构没有这些字段，不会伪造匹配结果。

运行时禁止使用模糊匹配决定实际下单商品。未确认或冲突的 SKU 应从可售列表中排除。

### 阶段三：首页、分类和商品详情（已实现）

交付内容：

- 首页分类树按模式读取。
- 首页设计模块的商品列表按模式读取。
- 分类列表、品牌列表和搜索结果按模式读取。
- 商品详情、相关推荐和自动完成按模式读取。
- 商品 URL 使用共享 URL Key 或原始路由参数保持不变。
- Open Graph、JSON-LD、面包屑和分页链接使用同一模式的数据。

实现要点：

- 商品、SKU、描述、分类和品牌模型在请求上下文激活期间自动使用 `mysql` 或 `catalog_public` 连接。
- 展示库没有 `category_paths`、属性和商品关系表时，前台查询走展示库已有的直接关系，避免跨库查询。
- 商品和分类显式绑定数字 `url_id`，展示商品 URL 优先使用已发布商品映射或路由映射的真实 URL 身份。
- 分类树、商品名称、品牌名称和商品仓储静态缓存均包含 `real/public` 作用域。
- 新迁移 `2026_07_30_000002_add_catalog_route_maps.php` 增加稳定 URL 映射表，并兼容已存在的展示库字段。

当前商品 URL 使用数字参数时，两个商品库无需保持主键一致；统一使用后文的数字 URL 映射表解析真实记录和展示记录。展示商品模型生成 URL 时应保留原始路由 Key，避免生成新的展示商品 URL。

### 阶段三补充：数字 URL 兼容方案（已实现）

当前项目使用以下数字 URL：

```text
/products/123
/categories/8
```

这里的数字应定义为 `url_id`，表示对外稳定的路由身份；它不再直接等同于两个数据库中的商品主键或分类主键。

建议在主库增加路由映射表：

```text
catalog_route_maps
├── id
├── route_type          product / category
├── url_id              对外数字 URL
├── real_record_id      真实库记录 ID
├── public_record_id    展示库记录 ID
├── status
├── mapping_version
├── created_at
└── updated_at
```

商品示例：

```text
route_type=url_id=product=123
real_record_id=123
public_record_id=7
```

访问 `/products/123` 时：

```text
真实模式    => catalog_real.products.id = 123
展示模式    => catalog_public.products.id = 7
```

对于多个真实商品映射到同一个展示商品，只增加多条路由记录：

```text
url_id=101 -> public_record_id=7
url_id=102 -> public_record_id=7
url_id=103 -> public_record_id=7
```

这不会要求展示库复制 6 万条商品，只维护 6 万条轻量路由映射。

路由实现建议：

1. 在商品模式上下文建立后，使用显式 `Route::bind('product', ...)` 解析数字 `url_id`。
2. 根据模式从 `real_record_id` 或 `public_record_id` 加载商品。
3. 将原始 `url_id` 放入商品展示对象的 `source_url_id` 属性。
4. 商品、推荐、面包屑和分页链接统一使用 `source_url_id` 生成 URL。
5. 分类使用同样的 `route_type=category` 逻辑。
6. 映射缺失或已停用时返回标准 404，不切换到另一个商品 URL。

前台 AJAX/API 请求必须携带同一模式 Cookie并使用相同的 `StoreContext`。支付回调、退款回调和 Webhook 通过订单号、签名和订单来源解析主库订单，单独排除前台商品模式中间件。

不要直接让展示商品模型的 `id` 参与 URL 生成。当前 `Product` 模型会根据模型 ID生成 URL，需要通过路由绑定、资源层或 `model.product.url` Hook 保留原始 `url_id`。

不推荐通过给展示库商品强行补齐真实 ID实现主键对齐，因为多对一映射会产生主键冲突，也会让商品关系、SKU关系和后续导入维护变得复杂。

### 阶段四：购物车、收藏和结算（基础链路已实现）

购物车和收藏仍写入主库，但明细保存商品库来源：

```text
catalog_mode
catalog_product_id
catalog_sku_id
fulfillment_sku
```

当前已实现：

- 主库迁移增加购物车和收藏的商品库模式及库内 ID 字段；历史真实购物车和收藏自动回填 `real` 来源。
- 购物车读取、加购、数量更新、删除和访客购物车合并按每条明细的 `catalog_mode` 加载对应商品库。
- 真实商品和斗篷商品可以同时存在于同一客户购物车，商品 URL继续使用稳定数字 URL。
- 收藏列表和商品卡片的收藏状态按 `catalog_mode` 与库内商品 ID隔离。
- 展示 SKU只有存在已发布确认映射时才进入可售和加购链路，并保存确定的 `fulfillment_sku`；未确认 SKU直接过滤，避免进入旧订单流程。

阶段四暂不改造订单明细、支付回调、库存状态机和发货流程，这些字段和校验在阶段五接入。

购物车读取时，通过 `CatalogResolver` 从对应商品库加载商品、规格、价格、图片和库存。

同一个客户可以同时保存两种模式的商品。结算时建议按 `catalog_mode` 分组：

- 纯真实商品：进入正常支付流程。
- 包含展示商品：订单标记为人工确认，或按模式拆分订单。

### 阶段五：订单、支付、库存和发货（订单快照与履约库存已实现）

订单主记录继续写入主库，订单商品保存：

```text
catalog_mode
catalog_product_id
catalog_sku_id
fulfillment_sku
name
image
price
quantity
```

如果展示商品也是实际发货商品，`fulfillment_sku` 使用展示库 SKU；如果展示商品是替身，`fulfillment_sku` 使用真实库存 SKU。

订单状态机需要按履约 SKU执行：

- 创建订单时重新读取商品价格和库存。
- 支付成功后扣减履约 SKU库存。
- 取消、退款和售后时恢复履约 SKU库存。
- 销量统计写入对应商品库或主库统计表。
- 支付回调根据订单号定位订单，不依赖 IP、Cookie 或当前展示模式。

当前实现：

- `order_products` 保存 `catalog_mode`、商品库内商品/SKU ID、履约 SKU、映射版本和下单时名称、图片、价格、数量。
- 创建订单前重新读取履约 SKU 库存；展示 SKU 没有已确认履约身份时拒绝创建订单。
- 支付成功通过现有 `StateMachineService` 按订单快照扣减库存，退款恢复原履约库存；真实库和展示库使用显式连接，不依赖当前请求 Cookie。
- 库存条件扣减和订单状态变更处于同一事务，库存不足时支付状态不会落库。
- 后台订单列表和 API 支持按 `catalog_mode`、`fulfillment_sku`、`catalog_mapping_version` 筛选。
- 现有支付插件的回调适配器仍需在启用插件和真实支付沙箱中验证其是否按订单号调用 `StateMachineService`。

### 阶段六：后台管理和供应商 IP 接口（基础能力已实现）

后台功能：

- key 新增、禁用、删除、过期时间设置。
- IP 白名单和黑名单管理。
- 黑名单供应商配置、同步和测试。
- 商品映射批量导入、冲突查看和人工确认。
- 真实商品与展示商品预览。
- 订单模式、展示 SKU和履约 SKU查询。
- 展示订单人工审核和转正常履约。

当前实现提供 `IpProviderInterface`、HTTP JSON/文本适配器、IPv4/IPv6/CIDR 标准化、本地缓存表、同步日志、失败策略、后台 API 和 `cyber-cloak:sync-ip-provider` 定时命令。请求过程只读取本地缓存，不直接调用供应商接口。具体供应商字段映射和支付插件沙箱联调仍需按部署环境验收。

## 5. 验收标准

阶段一验收请求上下文、Cookie、key、IP 规则、前台 HTML/API 一致性、Horizon 路由隔离和请求清理。

阶段二验收展示库迁移、JSON 导入幂等性、SKU 精确匹配、多对一映射、pending/conflict 状态、映射版本发布和显式连接选择。购物车/收藏来源隔离属于阶段四验收；订单快照和履约库存属于阶段五验收。

- 无 key、无 Cookie 请求进入展示模式。
- 有效 key 首次请求返回 `Set-Cookie`，页面无跳转。
- 有效 Cookie 请求进入真实模式。
- Cookie 过期后自动回到展示模式。
- 黑名单 IP即使携带有效 key也进入展示模式。
- 首页、分类、搜索、详情、推荐和 API 使用同一模式。
- 前台 HTML 和前台 API 的模式结果一致；后台、支付回调和 Webhook 不受访问者 IP或 Cookie影响。
- 分页链接不携带原始 key。
- Horizon 路由使用独立中间件组，不执行前台商品上下文解析，不签发或清理 `beike_context`。
- Octane 或常驻进程下请求结束后连接状态已恢复。

以下交易链路验收属于阶段三至阶段五；其中支付插件回调和真实数据库联调需要在对应沙箱环境执行：

- 同一个商品 URL不发生商品 URL跳转。
- 真实 SKU 和展示 SKU 的规格、价格、库存校验正确。
- 展示商品可以完整加入购物车、收藏、结算和下单。
- 订单保存展示 SKU、履约 SKU和商品快照。
- 支付回调、退款、取消和发货不依赖当前 IP或 Cookie。
- Cloudflare HTML缓存不会混用两种模式。

## 6. 主要风险

- 商品库同步延迟导致展示价格、规格或库存与履约数据不一致。
- 数字 URL 被误当成数据库主键，导致展示模式加载错误商品或生成新的商品 URL。
- SKU 多对一映射缺少规格级关系，导致购物车中的规格无法正确结算。
- Eloquent 关系和 `DB::table()` 查询跨数据库时使用了默认连接，导致商品、SKU、分类关系串库。
- 商品资源、面包屑、推荐和分页没有携带原始 `url_id`，导致站内链接跳转到展示商品自身 ID。
- 路由映射缺失、停用或版本不一致，导致同一个数字 URL在不同节点返回不同结果。
- 缓存 key未包含模式、语言、币种和映射版本，导致页面或商品数据串库。
- CDN缓存 HTML 导致请求没有到达应用，用户拿到另一模式的页面。
- Cloudflare、Nginx 和 Laravel 对真实 IP的信任链配置不一致，导致白名单或黑名单判断偏差。
- IP供应商数据过期、接口失败或 CIDR解析错误，导致模式误判。
- 客户 IP变化后模式发生变化，购物车必须依据每条明细保存的 `catalog_mode` 读取商品。
- 支付回调、退款和发货依赖当前 Cookie或 IP，导致异步请求找不到正确订单或履约 SKU。
- 映射版本发布过程中出现半批量状态，导致部分商品使用旧映射、部分商品使用新映射。

数字 URL 的处理原则是：

```text
URL 数字 = 路由身份
真实库 ID = real_record_id
展示库 ID = public_record_id
实际发货 SKU = fulfillment_sku
```

部署时先构建并校验 `catalog_route_maps`，再启用展示模式。映射版本使用原子发布；旧版本仍保留到全部缓存清理和订单验证完成。
