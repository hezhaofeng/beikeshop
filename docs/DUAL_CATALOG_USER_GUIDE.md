# cyberCloak 插件：同域双商品库使用手册

> 当前版本已提供阶段一至阶段五链路，以及阶段六 key 管理、供应商 IP 本地缓存/同步和展示订单审核基础能力。支付插件回调仍需在沙箱环境联调。

## 1. 功能说明

最终方案计划在同一个域名和同一个 URL 下提供两种商品数据：

- 真实模式：展示真实商品库商品。
- 展示模式：展示展示商品库商品。

阶段三已交付：

- 商品浏览。
- 分类和搜索。
- 品牌列表、品牌详情和自动完成。
- 商品详情、SKU 展示、相关推荐和首页商品模块。
- 真实模式与展示模式的稳定数字 URL。

阶段四和阶段五已包含：

- 购物车、收藏商品、结算和下单。
- 订单快照、履约 SKU 库存扣减/恢复和后台订单筛选。

具体支付插件回调仍需在沙箱环境联调。

模式切换由有效 Cookie、key、IP 白名单和 IP 黑名单共同决定。商品模式切换不会改变商品 URL，也不会通过商品 URL跳转。

当前版本还可以验证 `StoreContext` 的 `real/public` 判定、请求清理、前台 HTML/API 上下文一致性、Horizon 路由隔离和前台商品查询切库。

## 2. 访问模式规则

```text
有效 key 或有效 Cookie
且 IP满足白名单要求
且 IP不在黑名单
    => 真实模式

其他情况
    => 展示模式
```

黑名单优先级最高。首次使用有效 key时当前请求立即进入真实模式，并通过响应下发名为 `beike_context` 的 Cookie。白名单为空时表示不启用白名单限制。

### key 参数

首次访问可以使用：

```text
https://example.com/?key=ACCESS_KEY
```

有效 key 会通过响应下发 Cookie。页面继续使用当前 URL，不需要等待跳转。

后续访问依赖 Cookie，不建议继续在站内链接中拼接 key。

前台 AJAX/API 请求沿用同一个模式 Cookie。支付回调、退款回调和 Webhook 根据订单号和签名读取主库订单，不依赖访问者 Cookie或 IP。

### key 过期时间

后台支持：

- 按分钟或日期设置过期时间。
- 设置 `0` 表示服务端永久有效。
- 禁用 key 后立即失效已有 Cookie。

浏览器对长期 Cookie存在保留周期限制，永久 key 会通过有效请求自动续期。

## 3. 后台配置 key

进入后台插件管理，打开 `cyberCloak` 插件配置：

1. 创建 key名称。
2. 生成或录入 key。
3. 设置过期时间。
4. 设置状态为启用。
5. 保存并复制一次性访问地址。

建议每个业务场景使用独立 key，例如：

```text
运营测试
供应商测试
客服测试
内部验收
```

key 禁用后，已经签发的 Cookie也应在下一次请求重新验证时失效。

## 4. IP 白名单和黑名单

支持：

- 单个 IPv4。
- 单个 IPv6。
- CIDR网段。
- 手工规则。
- 第三方供应商同步规则。

白名单配置后，只有白名单中的 IP具备进入真实模式的资格。黑名单命中后直接进入展示模式。

Cloudflare 场景下，IP判断使用 Nginx real-ip 处理后的客户端地址。源站不直接把任意请求头当作真实 IP。

## 5. 商品库和映射管理（阶段二已实现）

执行插件迁移后，展示商品库使用 `catalog_public` 连接，主库保存映射版本和商品/SKU 映射。先在后台插件管理中安装并启用 `cyberCloak`，安装流程会自动执行插件迁移，再使用以下命令导入商品数据：

```bash
php artisan cyber-cloak:import-catalog storage/app/catalog.json --dry-run
php artisan cyber-cloak:import-catalog storage/app/catalog.json
php artisan cyber-cloak:rebuild-sku-mappings --mode=exact
```

日常维护可直接进入后台“插件管理 → cyberCloak → 编辑”，在“商品映射与首页链接”面板中使用四个按钮：

- “商品自动映射”：全量扫描真实库 SKU；匹配完整时自动发布并重建商品链接，出现待确认或冲突时先处理下方候选，之后再次点击同一按钮发布草稿；
- “分类链接同步”：基于当前已发布商品映射重建分类链接；
- “Banner 链接检查”：扫描首页装修的商品、分类和自定义链接，显示已映射与未映射统计；
- “清除缓存”：清理应用、配置、视图和优化缓存。

四个操作共用同一个已发布映射版本，首页装修仍只维护真实库配置，不维护 Cloak 第二套首页。新增模块只要沿用装修器的 `{type, value}` 链接结构，即可被 Banner 扫描自动识别。

如果真实库和 Cloak 库不是同一批商品，但测试需要直接进入真实模式，可以使用随机固定映射：

```bash
php artisan cyber-cloak:rebuild-sku-mappings --mode=random --publish
php artisan cyber-cloak:rebuild-route-mappings --type=product --mapping-version=VERSION
```

`random` 首次为每个真实商品选择一个 Cloak 商品并写入映射表，后续重建会复用最近随机版本中的商品和 SKU 关系；只有新增真实商品或展示记录失效时才会重新选择。真实 SKU 的 `fulfillment_sku` 不会被展示 SKU 替换。

JSON 文件的顶层字段为 `brands`、`categories` 和 `products`。商品可以包含 `descriptions`、`category_ids` 和 `skus`；重复导入带稳定 `id` 的数据会更新对应记录。

映射以 SKU为最小单位，支持多对一：

```text
真实 SKU A -> 展示 SKU 1
真实 SKU B -> 展示 SKU 1
真实 SKU C -> 展示 SKU 2
```

映射状态：

```text
confirmed  已确认
pending    待确认
conflict   冲突
disabled   停用
```

推荐操作流程：

1. 导入展示商品和 SKU。
2. 执行精确匹配。
3. 查看待确认和冲突列表。
4. 检查规格、价格、库存和履约 SKU。
5. 确认映射。
6. 发布映射版本。

确认命令示例：

```bash
php artisan cyber-cloak:confirm-sku-mapping VERSION REAL_SKU_ID PUBLIC_SKU_ID --fulfillment-sku=FULFILLMENT_SKU
php artisan cyber-cloak:confirm-sku-mapping VERSION REAL_SKU_ID PUBLIC_SKU_ID --publish
```

精确模式只确认唯一的 SKU/规格或型号/规格候选；多个候选保存为 `conflict`，没有候选保存为 `pending`。`candidate` 模式可以生成同名商品候选，但仍需要人工确认，不会直接用于下单。

未确认的 SKU不进入可售列表，避免用户下单后出现履约错误。

## 6. 首页和分类配置（阶段三已实现）

展示模式下建议整体使用展示分类：

- 首页分类使用展示分类树。
- 首页商品模块使用展示商品。
- 分类列表使用展示商品数量和分页。
- 商品详情中的相关推荐使用展示商品。
- 搜索、品牌和自动完成使用展示库。

真实模式使用真实商品库对应内容。

商品链接保持当前 URL结构，站内链接不追加 key参数。

### 首页装修映射

首页装修继续只维护一套真实库配置，布局、幻灯片、商品模块和选项卡均保存真实商品 ID；后台调整首页模块或推荐商品后，真实模式直接读取这些 ID，展示模式在渲染时按已发布路由映射转换为 Cloak 商品 ID。

- `product`、`tab_product` 和 `latest` 商品内容按 `real_product_id -> public_product_id` 转换；没有已发布展示映射的商品会从展示列表中移除。
- `slideshow`、图文幻灯片、多图 banner、图标等模块中，通过装修器选择的 `product` / `category` 链接使用稳定 URL映射。
- 自定义硬编码 URL不会解析其中的商品 ID；需要使用装修器的商品或分类链接选择器，才能参与自动映射。
- 首页导航仍保存在 `base.menu_setting`，首页模块保存在 `base.design_setting`，两者共用同一套路由映射版本；分类按钮负责导航和分类链接，Banner 按钮负责首页装修模块中的链接检查。

发布商品映射后，应重建商品和分类路由，使首页商品卡片、banner、导航和详情页使用同一批稳定 URL：

```bash
php artisan cyber-cloak:rebuild-route-mappings --type=product --mapping-version=VERSION
php artisan cyber-cloak:rebuild-route-mappings --type=category --mapping-version=VERSION
```

### 数字 URL 规则

当前商品和分类 URL使用数字参数，但数字参数代表稳定的对外路由身份，不直接代表当前数据库中的商品主键：

```text
/products/123

真实模式    -> 真实库商品 ID 123
展示模式    -> 展示库商品 ID 7
```

多个真实商品可以指向同一个展示商品：

```text
/products/101 -> 展示商品 7
/products/102 -> 展示商品 7
/products/103 -> 展示商品 7
```

用户看到的 URL保持不变。商品卡片、推荐、面包屑和分页链接都必须使用原始 `url_id`，展示商品自己的数据库 ID不直接出现在链接中。

路由映射存在时，映射缺失或已停用的数字 URL返回 404；迁移尚未执行的分阶段环境保留同 ID兼容行为。系统不会把当前请求跳转到另一个商品 URL。

## 7. 展示模式下单（阶段五已实现）

展示商品进入结算时会按以下链路生成订单：

```text
选择展示商品
    -> 仅允许已确认 SKU 加入购物车
    -> 创建订单前重新校验履约 SKU 和库存
    -> 主库订单保存不可变商品快照
```

订单详情保存：

- 当前商品模式。
- 展示商品和 SKU。
- 实际履约 SKU。
- 下单时商品名称、图片和价格。

订单创建后不依赖当前 Cookie重新解析商品，历史订单以订单快照为准。

展示商品的 `fulfillment_sku` 默认来自已发布映射中的真实 SKU。若映射人工确认时指定展示库 SKU，库存服务会在展示库中按展示 SKU 查找履约库存。

## 8. 订单处理（阶段五已实现）

后台订单列表和管理 API 支持筛选：

- 全部订单。
- 真实模式订单。
- 展示模式订单。
- 履约 SKU。
- 映射版本。

如果展示商品使用展示库库存，库存直接扣减展示库 SKU；如果展示商品是替身，库存扣减真实库履约 SKU。支付成功、退款和已支付订单取消均使用订单保存的履约身份。

支付回调、退款、取消和发货根据订单号读取订单，不依赖当前请求的 IP、Cookie和商品模式。

订单状态和库存扣减在同一事务中执行，库存不足时状态变更回滚。支付插件的实际回调请求仍需在对应沙箱环境中验证。

## 9. IP 供应商同步（阶段六已实现基础能力）

在插件编辑页配置供应商，或使用以下命令：

```bash
php artisan cyber-cloak:test-ip-provider
php artisan cyber-cloak:sync-ip-provider --dry-run
php artisan cyber-cloak:sync-ip-provider
```

配置项包括适配器、接口地址、Token、超时、缓存 TTL、同步周期、空列表策略和失败策略。后台 API 端点位于 `/{admin_name}/cyber-cloak/ip-provider`，key 管理端点位于 `/{admin_name}/cyber-cloak/keys`。

供应商数据会标准化为 IPv4、IPv6 和 CIDR 规则，并在本地缓存。页面请求不会直接调用供应商接口；展示订单审核可通过 `POST /{admin_name}/cyber-cloak/orders/{order}/review` 设置 `pending`、`approved` 或 `rejected`。

## 10. 故障排查

### 有效 key 仍然进入展示模式

检查：

- key 是否启用。
- key 是否已过期。
- Cookie 是否成功下发。
- IP 是否命中黑名单。
- Nginx 是否正确传递客户端 IP。
- Cloudflare 是否命中旧缓存。

### 真实商品页面显示展示商品

检查：

- Cookie内容和过期时间。
- 当前请求模式日志。
- SKU映射状态。
- Cloudflare Cache Status。
- PHP-FPM 是否存在常驻连接状态污染。

### 购物车商品无法加载

检查：

- `catalog_mode` 是否保存。
- `catalog_product_id` 和 `catalog_sku_id` 是否正确。
- 展示库 SKU 是否启用。
- 商品库连接是否可用。
- 映射版本是否已经发布。

### 数字 URL显示错误商品

检查：

- `catalog_route_maps` 是否存在对应的 `route_type` 和 `url_id`。
- `real_record_id` 和 `public_record_id` 是否分别指向正确数据库。
- 路由映射版本和 SKU映射版本是否一致。
- 商品资源是否使用 `source_url_id` 生成链接。
- 商品详情是否仍然使用隐式模型 ID生成 URL。
- 应用缓存和 CDN缓存是否已经清理。

### 支付回调找不到订单

检查：

- 回调是否按订单号读取主库。
- 回调路由是否绕过前台商品模式中间件。
- 订单号是否全局唯一。
- 支付供应商签名验证是否通过。

## 11. 日常维护

建议每日检查：

- key 失效数量。
- IP 黑名单同步状态。
- SKU 待确认和冲突数量。
- 展示库连接状态。
- 商品库存同步时间。
- 展示模式订单数量。
- 支付失败和回调失败日志。
- Cloudflare 缓存命中情况。

建议每次商品库变更后执行：

```text
导入商品
同步 SKU
重建精确映射
处理冲突映射
发布映射版本
清理相关缓存
抽样测试商品详情、购物车和订单
```
