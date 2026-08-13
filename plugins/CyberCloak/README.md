# cyberCloak

本插件是同域双商品库方案。它根据请求中的有效访问 key、签名 Cookie 和 IP 访问规则建立 `real` 或 `public` 的 `StoreContext`，并驱动前台商品浏览、展示库导入、商品/SKU 映射、分类稳定 URL 和首页内容映射；订单、购物车与收藏保持 BeikeShop 原有流程。

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
php artisan cyber-cloak:confirm-sku-mapping VERSION REAL_SKU_ID PUBLIC_SKU_ID
php artisan cyber-cloak:sync-published-sku-index
```

精确匹配按 SKU 加规格、SKU、型号加规格、型号依次查找。唯一候选才会标记为 `confirmed`；没有候选为 `pending`，多个候选为 `conflict`。候选模式可以按同名商品生成待人工确认数据，但不会直接确认。

### 大目录展示 SKU 索引

发布映射时，插件会分批把已确认的展示 SKU 写入 `catalog_public.catalog_published_sku_mappings`。首页、分类、搜索、品牌和商品详情通过本地索引关联过滤，不会把全部映射 SKU 读入 PHP 或生成超大 `WHERE IN`。

升级已有已发布映射时，先执行插件迁移，再回填当前版本索引：

```bash
php artisan migrate --force --path=plugins/CyberCloak/Migrations/2026_08_07_000011_create_catalog_published_sku_mappings.php
php artisan cyber-cloak:sync-published-sku-index
php artisan optimize:clear
```

之后每次发布新商品映射都会自动更新该索引；无需重新生成映射或重新同步分类链接。

推荐使用后台插件编辑页中的“商品映射与首页链接”面板完成日常操作。面板提供六个按钮：

- “生成商品映射”：提交队列任务，按选中的精确、候选或随机模式新建一个映射版本；页面轮询任务进度，无待确认项时自动发布并重建商品链接，候选模式的异常行保留为草稿；
- “发布当前草稿”：提交队列任务，仅在当前草稿已有商品和 SKU 映射，且不存在待确认或冲突项时可用；发布后同步商品链接；
- “分类链接同步”：使用当前已发布版本重建分类稳定链接，首页导航和分类 Banner 共用这套路由；
- “Banner 链接检查”：扫描 `base.design_setting` 中的首页模块链接，统计商品、分类、自定义链接及未映射项，不复制第二套装修配置；
- “扫描 Banner 图片”：登记 `base.design_setting` 中的真实图片字段，并在映射面板中逐条填写 Cloak 图片路径或 JSON；展示模式按 `StoreContext` 替换图片，真实模式保持原图；
- “清除缓存”：清理应用、配置、视图和优化缓存，使最新首页装修与映射立即生效。

### 异步商品映射

商品映射会遍历全量商品和 SKU，并在发布后重建商品稳定链接。后台请求只创建 `catalog_mapping_tasks` 记录并投递 `cyber_cloak` 队列，避免 Cloudflare 等代理等待 HTTP 响应超时。页面会轮询“排队中、执行中、已完成或失败”状态；失败摘要会显示在面板中，完整堆栈仍记录在 Laravel 日志和 `failed_jobs`。

升级后先执行插件迁移，再启动常驻 Worker：

```bash
php artisan migrate --force --path=plugins/CyberCloak/Migrations/2026_08_10_000012_create_catalog_mapping_tasks.php
php artisan queue:work cyber_cloak --queue=cyber_cloak --timeout=3600 --tries=1 --sleep=3
```

`CYBER_CLOAK_QUEUE_RETRY_AFTER` 默认是 `7200` 秒，必须大于 Worker 的 `--timeout=3600`，防止长任务仍在运行时被重复消费。Docker Compose 部署会启动同名 `queue` 服务；非 Docker 部署请使用 Supervisor、systemd 或 Horizon 守护上面的 Worker 命令。

首页装修继续只维护真实库的商品和分类 ID。新增幻灯片、选项卡或其他模块时，只要链接沿用装修器的 `{type, value}` 结构，Banner 检查会自动纳入该模块。

### 首页 Banner 图片映射

Banner 图片映射使用主库 `catalog_home_banner_mappings`，不在 `catalog_public` 复制首页布局。系统递归扫描每个模块的 `image` 字段，以 `module_id + 源图片 src 哈希` 作为稳定身份，幻灯片拖拽排序或修改 alt 文案不会改变已经保存的映射。

后台操作顺序：

1. 点击“扫描 Banner 图片”，登记当前首页的真实图片；
2. 为每条记录点击上传按钮，复用 BeikeShop 后台图片上传并自动保存；也可以填写已有 Cloak 图片路径，例如 `/image/catalog/cloak/banner-1.webp`，或填写与源图片结构一致的 JSON；
3. 点击“保存”，状态变为“已启用”；
4. 上传会由 BeikeShop 自动写入现有图片存储目录；保存后点击“清除缓存”；
5. 用无 key 的展示请求和有效 key 的真实请求分别检查两套图片。

展示请求遇到待配置或已停用的映射时会清空该图片源，不回退展示真实 Banner；迁移尚未执行时保留旧版原图以支持分阶段上线。

`random` 模式用于两边商品不是同一批数据、但需要先固定展示商品的测试场景。它按真实商品选择一个 Cloak 商品，同一真实商品的多个 SKU 映射到该 Cloak 商品下的启用 SKU。首次生成后，后续随机重建会优先读取最近随机版本中的商品和 SKU 映射；只有新增真实商品或原 Cloak 商品/SKU 已失效时才重新选择。因此固定关系来源是映射表，不是请求参数或内存缓存。

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

后台可为任一有效 key 生成并复制 `APP_URL/?key=KEY_VALUE` 分享链接。新建 key 会同时保存原文和 SHA-256 摘要，链接使用原文 key；停用、删除或到期后，该链接会立即失效。历史上仅保存 SHA-256 摘要的记录无法恢复原文，需要使用原 key 重新创建后再分享。

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

## 阶段六：三段式后台管理

- 基础设置集中维护访问 key、IP 白名单、黑名单和信誉网段，并只读展示插件、真实库和展示库连接状态。
- 高级设置集中维护 Country、ASN、匿名 IP 和云 IP 汇总 JSON 文件路径，以及国家、语言、UA、ASN、数据中心 ASN、频控和风险阈值。
- 高级设置的风险 IP 审核面板以加密 IP 档案展示达到审核分数的行为事件，可标记待审核、可信、可疑或封禁；可信只跳过行为识别，可疑保留软风险分，封禁在无凭据漏斗中优先拦截。
- 映射管理集中展示已发布版本、商品映射待确认项、分类链接同步、Banner 检查、首页转换状态和缓存清理。
- 历史版本可能已经创建 `cyber_cloak_ip_provider_*` 表；新版本不再读取、写入或调度这些表，保留它们只为兼容已有数据库。
- 后台 API 提供 key 脱敏列表、创建、启用、禁用和删除。

## 阶段七：四层流量漏斗

前台会先执行现有 IP 黑白名单硬规则，再校验 query key 和签名 Cookie；有效凭据直接进入真实站。只有没有有效凭据的请求才会执行四层漏斗，静态黑名单仍然优先于 key。

1. 急速拦截：本地静态 IP/CIDR 黑名单、允许国家/地区和基础 UA 校验；高置信度黑名单直接阻断。配置允许国家/地区后，未匹配及 GeoLite 未识别来源固定进入展示漏斗。
2. 核心风控：本地 MaxMind MMDB 的 Country/ASN 信息、匿名 IP 数据（若配置）、IP 信誉网段和 ASN 规则；云 IP 汇总命中返回 `DATACENTER` 与厂商信号。
3. 规则匹配：可选 Laravel `RateLimiter`，按 IP 与路由计数；频控结果返回 `rate_limit` 动作，不在请求中访问外部网络。
4. 深度识别：自动化 UA/请求头线索和可选指纹 Cookie 只作为风险信号；浏览器端指纹脚本应按登录、注册、支付和下单路由单独接入。

允许国家/地区、浏览器语言白名单和 UA 黑名单属于无凭据流量的漏斗信号，在 key/Cookie 校验失败后执行；配置允许国家/地区时，未匹配及未知地区一律进入展示模式。有效 key 或签名 Cookie 保持既有优先级。浏览器语言读取 `Accept-Language`，支持 `zh` 与 `zh-CN` 的基础语言兼容匹配；UA 黑名单使用大小写不敏感关键词匹配。

漏斗结果包含 `action`、`stage`、`score`、`reasons` 和 `signals`。`block`、`challenge`、`rate_limit` 的无 key 请求进入展示模式并保留原因，便于先使用 Shadow Mode 观察误杀，再逐步调高强度。MaxMind 数据库通过 `CYBER_CLOAK_GEOIP_*_DATABASE` 配置本地路径，云 IP 汇总库通过 `CYBER_CLOAK_CLOUD_IP_RANGES_DATABASE` 配置；前台不在请求路径访问远程 API。

云 IP 汇总库推荐使用以下结构，文件更新后按修改时间自动重新加载：

```json
{
  "version": 1,
  "updated_at": "2026-08-04T00:00:00Z",
  "providers": {
    "AWS": ["3.5.140.0/22"],
    "GCP": ["34.64.0.0/10"],
    "CLOUDFLARE": ["173.245.48.0/20"]
  }
}
```

ASN 或云网段只能提供网络类型线索，`DATACENTER` 不等于恶意来源；没有云网段命中但存在 ASN 时返回 `ISP_LIKELY`，其余情况为 `UNKNOWN`。

## 阶段八：行为识别与风险 IP 审核

无有效 key/Cookie 的请求会在配置的短窗口内统计请求数、不同路由数和重复无效上下文 Cookie。超过阈值时生成 `behavior_request_burst`、`behavior_route_scan` 或 `behavior_invalid_context_cookie` 原因码；这类模式可发现未在 UA 黑名单中的新自动化流量，但不会把单个行为信号直接等同于恶意来源。

若行为异常同时来自匿名 IP、云/数据中心网段或后台指定的风险 ASN，插件会额外写入 `behavior_network_activity`，其中保留 ASN、匿名标记和云厂商上下文。普通 ASN 只作为审计信息，不单独提高行为分，避免把正常运营商网络误判为自动化流量。

风险审核功能依赖本次新增的两张主库表。升级已安装插件后执行单文件迁移，再清除配置和视图缓存：

```bash
php artisan migrate --force --path=plugins/CyberCloak/Migrations/2026_08_04_000008_add_traffic_risk_audit.php
php artisan optimize:clear
```

审计表以 IP HMAC 关联档案、以 Laravel 加密值保存 IP，审核列表仅返回脱敏地址。后台“可信”不会绕过 IP 黑名单、国家/语言/UA、ASN、云网段或频控规则；“封禁”只参与无凭据请求，已有有效 key 或签名 Cookie 保持既有优先级。
- 展示订单创建时写入 `pending` 审核状态；审核为 `approved` 后再进入正常履约处理。
