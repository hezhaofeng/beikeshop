# PayPal 跨站内嵌支付（embedded 模式）设计与评估

## 1. 目的与适用范围

在现有 PaypalA / PaypalB 跨站支付（A 站顶层跳转到 B 站托管页）和 PaypalB 本站本地支付之外，新增第三种模式：**A 站支付页内嵌 B 站 PayPal 支付页（iframe），买家全程停留在 A 站界面，实际由 B 站 PayPal 账号收款**。同时把 B 站镜像订单从"支付完成后创建"提前到"A 站下单后即创建"，并按 B 站商品目录自动分配商品、抹平差价。

约束：

- 现有跳转模式（redirect）和 B 站本地支付（local）必须原样保留，三种模式可同时运行；
- 已创建的历史会话不受影响；
- 不改变 A/B 之间的签名、回调、状态机与对账机制。

本文只做方案设计与评估，不包含代码改动。引用的行为以当前代码为准。

## 2. 现状要点（与本方案直接相关）

| 项目 | 现状 | 代码位置 |
|---|---|---|
| A 站支付页 | 表单 POST `start` → 创建 B 站会话 → 302 顶层跳转到 B 站 `checkout_url` | `plugins/PaypalA/Controllers/PaypalAController.php` `start()` |
| A 站模式配置 | 没有 mode 字段，固定顶层跳转 | `plugins/PaypalA/columns.php` |
| B 站结账页响应头 | `Content-Security-Policy: frame-ancestors 'none'`，明确拒绝 iframe | `PaypalBController::checkoutView()`、`policyPage()` |
| B 站页面完成后动作 | `window.location.assign(returnUrl)` 顶层跳转，`finish` 302 到 A 站 `/paypal/return?reference=` | `Views/checkout/partials/wallet_buttons.blade.php`、`card.blade.php`、`finish()` |
| B 站 `checkout_mode` | 硬编码 `'hosted'` | `PaypalBConfiguration::from()` |
| B 站镜像订单 | 仅在交易 `completed` 且非 local 时创建；直接以 PAID 写入，不走状态机；`product_id=0`，金额原样镜像，无差价处理 | `PaypalBShopOrderService::createFromTransaction()` |
| 商品映射 | A 站 `bridge_item_source=mapped` 时经 CyberCloak `SkuMappingService` 得到 B 站名称 / SKU，金额必须与 A 站一致 | `PaypalABridgeClient` |
| 支付结果同步 | B 站 outbox 签名回调 A 站，A 站 `returned()` 回跳时主动拉取补偿 | `PaypalBCallbackService`、`PaypalAController::returned()` |
| 库存 | 状态机在进入 PAID 等状态时扣减 SKU 库存 | `StateMachineService::subStock()` |

## 3. 方案总览

新增一个**会话级**属性 `presentation`（取值 `redirect` / `embedded`），由 A 站在创建会话时声明，B 站按该属性决定页面形态与回跳行为。模式选择落在 A 站配置，B 站只做"是否允许被 A 站内嵌"的开关。这样三种模式在 B 站是同一套会话、账号池、capture、Webhook、回调与对账代码，只有展示层分叉。

```
A 站支付页（A 域名）
 ├─ redirect 模式：表单 POST start → 302 → B 站托管页（现状）
 └─ embedded 模式：AJAX POST start → 得到 checkout_url
      └─ <iframe src="B站/paypal/checkout/{token}?method=wallet">
            ├─ PayPal Buttons / Card Fields（PayPal 自身弹窗完成登录、3DS）
            ├─ capture / status（B 站接口，capture_token 鉴权，与现状相同）
            └─ 完成后 postMessage 通知父页 → A 站查自身状态 → 顶层跳转 checkout/success
B 站
 ├─ 会话创建时：按 B 站目录分配商品、创建 UNPAID 镜像订单、写入差价调整行
 ├─ 支付完成：镜像订单 UNPAID → PAID，outbox 回调 A 站（现状）
 └─ 会话失效：镜像订单自动取消
```

## 4. 详细设计

### 4.1 A 站（PaypalA）

**配置**（`columns.php` 新增）

| 字段 | 说明 |
|---|---|
| `checkout_mode` | `redirect`（默认，现状）/ `embedded` |
| `embedded_status_poll_seconds` | 内嵌模式下 A 站自查状态的轮询间隔，默认 3 秒，范围 2–10 |

结账校验钩子 `service.checkout.validate_confirm.after` 增加一条：`embedded` 模式要求 B 站 `public_url` 为 HTTPS 且与 A 站不同源（浏览器对跨站 iframe 的第三方 Cookie 限制不影响本方案，见 6.2）。

**会话创建请求**：`PaypalABridgeClient::createSession()` 载荷新增 `presentation`。B 站不支持内嵌或未允许 A 站内嵌时返回 422，A 站自动降级为 redirect 并记录日志，不阻断买家付款。

**支付页** `Views/checkout/payment.blade.php`：

- `redirect` 模式保持现有两个表单；
- `embedded` 模式渲染：
  - 一个占位容器和 loading 状态；
  - 页面加载后 AJAX POST 现有 `paypal_a.orders.start`（需让 `start()` 在 `Accept: application/json` 时返回 `{checkout_url}` 而非 302），成功后插入 `<iframe src="{checkout_url}&presentation=embedded" allow="payment" referrerpolicy="no-referrer">`；不使用 `sandbox` 属性，否则 PayPal 弹窗会被拦截；
  - 钱包 / 信用卡切换：两种方式各自一个 iframe URL（`method=wallet|card`），切换即替换 `src`；会话方法锁定逻辑不变；
  - 监听 `message` 事件，只接受 `event.origin === b_site_url` 且 `data.reference` 与当前会话一致的消息，消息类型见 4.2；**消息只作触发信号，不作为订单状态依据**；
  - 收到 `completed` / `pending_capture` / `failed` 等信号后，调用 A 站新增接口 `GET paypal/orders/{number}/status`（下文），由 A 站服务端向 B 站拉取并 `applyBridgeResult()`，再按结果顶层跳转到 `checkout.success` 或原地展示错误 / 审核中提示；
  - 兜底轮询：iframe 加载后每 N 秒调用同一状态接口，防止 postMessage 丢失（例如买家在 PayPal 弹窗里关掉页面又回来）；
  - 高度自适应：接收 B 站 `resize` 消息调整 iframe 高度。

**新增接口** `GET paypal/orders/{number}/status`（`shop` 中间件组，throttle 30/min）：复用 `returned()` 里"按会话拉取 B 站状态并应用"的逻辑抽成 `PaypalAPaymentService::refreshFromBridge()`，返回 `{status, message, redirect_url?}`。游客授权沿用 `guest_order_numbers` 会话与邮箱校验。

**待确认捕获**（Payment Review）：状态接口返回 `pending_capture` 时，A 站支付页替换 iframe 为等待提示（现有 `capture_pending` 文案），并提供"查看订单"入口；后续由 B 站回调推进。

### 4.2 B 站（PaypalB）

**配置**（`PaypalBConfiguration`）

| 字段 | 说明 |
|---|---|
| `embedded_enabled` | 是否允许 A 站内嵌，默认 false |
| `checkout_mode` | 由硬编码改为按会话 `presentation` 派生，不再作为全局值 |

frame-ancestors 白名单直接取 `a_site_url` 的 origin，不提供自定义列表，避免误配成通配。

**会话**：`paypal_b_transactions` 新增列 `presentation`（`redirect` 默认 / `embedded`），`validateCreatePayload()` 校验：`embedded` 需 `embedded_enabled=true`，否则 422 并给出明确错误码供 A 站降级。

**结账页** `checkout()`：

- `presentation=embedded` 的会话：
  - `checkoutView()` 响应头改为 `Content-Security-Policy: frame-ancestors {a_site_origin}`；其他会话保持 `'none'`；
  - 使用新的精简布局 `Views/layout/embedded.blade.php`：无页头、无商户信息大标题、透明背景、只保留 PayPal 按钮区、金额和法务页脚链接（页脚链接加 `target="_blank"`，因为在 iframe 内打开政策页会再次被 CSP 拦截）；
  - 页面 JS 在 capture 完成、轮询超时、失败时向 `window.parent.postMessage({type:'paypal_b', event, reference}, a_site_origin)`；`targetOrigin` 必须是配置值而非 `*`；
  - 保留 `window.location.assign(returnUrl)` 作为兜底，但 `finish` 在 `embedded` 会话下不再 302 到 A 站，而是渲染一个只含 postMessage 和"返回商店"（`target="_top"`）链接的小页面，避免 iframe 内加载 A 站页面被 A 站自己的 X-Frame 策略拒绝或出现"页中页"；
- `presentation=redirect` 与 local 会话：行为完全不变。

**URL 参数防误用**：`presentation` 不从查询串读取，只认会话记录；A 站 iframe URL 上的 `presentation=embedded` 仅用于日志排查。

**capture / status 接口**：已免 CSRF、按 `capture_token` 鉴权、`credentials: omit`，跨站 iframe 内可直接工作，无需改动。

### 4.3 B 站镜像订单提前创建与商品分配

**时机**：新增配置 `mirror_order_timing`：`on_completed`（现状，默认）/ `on_session`。`on_session` 时在 `PaypalBTransactionService::createUnlocked()` 成功建 PayPal 订单后（即状态进入 `pending`）同步创建镜像订单，写入 `shop_order_id`。同一 A 站订单号只创建一个镜像订单，重试 / 重建会话时复用。

**商品分配**（新服务 `PaypalBMirrorOrderService`，替换 / 扩展 `PaypalBShopOrderService::buildOrder()`）：

1. 对 A 站每一行商品，按优先级在 B 站目录查找：`paypal_order_items[].sku`（CyberCloak 映射结果）→ `order_items[].sku` → 名称精确匹配；
2. 命中则写真实 `product_id` / `product_sku_id`，单价取 **B 站当前售价**，数量取 A 站数量；未命中则沿用现状（`product_id=0`，单价取 A 站单价），并在交易记录 `risk_flags` 打 `mirror_unmapped` 标记供后台筛查；
3. 复制 A 站 `order_totals` 中的运费、税费等费用行；
4. **抹平差价**：计算 `adjustment = A 站实付金额 − (B 站商品小计 + 复制的费用行)`，若不为 0，则追加一条 `order_totals` 行 `code=paypal_b_adjustment`（正为加价、负为折扣，标题"价格调整"），使镜像订单 `total` 严格等于 PayPal 实际捕获金额，后台对账不出现差额；
5. 镜像订单以 `UNPAID` 创建，`customer_id=0`，`number` 沿用 A 站订单号（撞号规则不变），`payment_method_code=paypal_b`，历史备注注明来源会话与调整金额。

**状态推进**：

| 交易事件 | 镜像订单动作 |
|---|---|
| `completed` | `StateMachineService` UNPAID → PAID（`notify=0`，不发邮件），写 `order_payments`；`on_completed` 模式仍按现状直接建 PAID 单 |
| `expired` / `cancelled` / `failed`（无捕获） | UNPAID → CANCELLED，备注原因 |
| 待确认捕获 | 保持 UNPAID，备注"PayPal 审核中"，与本地支付一致 |
| 全额退款 | 复用现有退款流程（PAID → REFUNDING → CANCELLED） |

**库存**：状态机进入 PAID 会扣减 B 站 SKU 库存。镜像订单默认**不扣库存**（B 站目录只是展示 / 收款载体，实际发货由 A 站履约），通过 `service.state_machine.machines` 钩子在 `payment_method_code=paypal_b` 且交易 `source=bridge` 时移除 `subStock`。是否扣减做成配置 `mirror_order_deduct_stock`，默认关闭。

**历史补建**：`paypal-b:backfill-orders` 增加 `--with-adjustment` 选项，对已存在的镜像订单补差价行；不回溯改单价。

### 4.4 兼容性矩阵

| 场景 | 行为 |
|---|---|
| A 站 `checkout_mode=redirect`（默认） | 与现状完全一致，B 站按 `presentation=redirect` 处理 |
| A 站 `embedded`、B 站未开 `embedded_enabled` | B 站 422，A 站自动降级 redirect 并记日志 |
| B 站本地支付（local） | 不受影响；`presentation` 对 local 会话无意义，固定 `redirect` |
| 升级前已创建的会话 | `presentation` 列默认 `redirect`，回跳、回调路径不变 |
| A 站切换模式时存在未完成会话 | 会话按创建时的 `presentation` 走完，支付页按会话记录而不是当前配置渲染 |
| `mirror_order_timing=on_completed` | 镜像订单逻辑与现状一致，差价行也不生成（金额本就相同） |

## 5. 实施评估

### 5.1 改动清单与工作量

| 模块 | 改动 | 估算 |
|---|---|---|
| PaypalA 配置与校验 | `columns.php`、`config_form`、`bridgeConfiguration()`、结账校验 | 0.5 天 |
| PaypalA `start()` JSON 响应 + 状态接口 + `refreshFromBridge()` 抽取 | 控制器、服务、路由、语言 | 1 天 |
| PaypalA 支付页内嵌 JS | iframe 装载、postMessage、轮询、方法切换、等待 / 错误态、移动端高度 | 1 天 |
| PaypalB 会话 `presentation` | 迁移、模型、载荷校验、`statusPayload` | 0.5 天 |
| PaypalB 结账页 embedded 布局 + CSP 按会话 + postMessage + `finish` 分支 | 控制器、布局、两个模板、语言 | 1 天 |
| PaypalB 镜像订单提前创建、商品分配、差价行、状态推进、库存钩子 | 新服务、迁移（`mirror_order_timing` 等配置无需迁移）、状态机钩子、后台交易列表展示 | 2–3 天 |
| 测试 | 沙盒双站联调（钱包 / 信用卡 / Payment Review / 取消 / 过期）、Safari 与 iOS、现有单元测试补断言 | 2 天 |
| 文档 | 两个 README、部署说明 | 0.5 天 |

合计约 8–10 个工作日，可拆为两个可独立上线的阶段：阶段一"内嵌支付"，阶段二"镜像订单提前创建与差价"。阶段二不依赖阶段一，也可单独上线。

### 5.2 风险与对策

1. **PayPal 账户合规**：PayPal 商户协议要求账户网站信息与实际收款站点一致，跨站内嵌会让买家在 A 域名下对 B 账号付款，与现有跳转模式相比更隐蔽，触发风控（资金冻结、Payment Review 比例升高、账户受限）的概率上升。这是业务决策层面的风险，技术上无法消除，建议上线前确认 B 站账号资料与 A 站品牌的关系可解释，并保留 redirect 模式随时回切。
2. **浏览器第三方 Cookie / ITP**：PayPal SDK 在跨站 iframe 中会强制使用弹窗完成登录与 3DS，Safari 会额外提示"允许弹出窗口"；PayPal 官方 SDK 支持这一场景，但需在 iOS Safari、Chrome 隐身模式实际验证。方案中 capture / status 已不依赖 Cookie。
3. **点击劫持面**：放开 `frame-ancestors` 只限 A 站 origin，且只对 `presentation=embedded` 的会话生效；政策页保持 `'none'`。绝不接受通配或从请求参数取 origin。
4. **postMessage 伪造**：A 站只把消息当触发信号，真实状态一律经服务端签名拉取；B 站发送时指定 `targetOrigin`。
5. **弃单产生的镜像订单**：`on_session` 会在 B 站产生大量 UNPAID 订单，需要会话过期 / 取消时自动 CANCELLED，并在后台订单列表按 `payment_method_code=paypal_b` 与来源过滤。现有 `paypal-b:sync-pending-captures` 与过期惰性判定已覆盖状态推进入口，需补一条"过期即取消镜像订单"的动作。
6. **差价行对报表的影响**：B 站销售报表会出现调整行，销售额仍等于实收；如需按商品统计毛利，`paypal_b_adjustment` 应在报表中单列。
7. **商品映射失败**：未命中时降级为无 `product_id` 行并打标，不阻断支付；后台交易列表增加"未映射"筛选。
8. **iframe 内的错误页与法务页**：政策页链接必须 `target="_blank"`；B 站错误页的"返回订单"链接需 `target="_top"`。

### 5.3 备选方案对比

| 方案 | 说明 | 优点 | 缺点 |
|---|---|---|---|
| A. iframe 内嵌 B 站页（推荐） | 本文方案 | PayPal 凭据与 SDK 仍只在 B 站；A 站零 PayPal 依赖；三种模式共用一套会话 | 需放开 CSP；跨站 iframe 下依赖弹窗；PayPal 合规风险上升 |
| B. A 站直接加载 PayPal SDK，用 B 站 client_id | A 站页面渲染按钮，createOrder / capture 仍走 B 站接口 | 无 iframe，体验最顺 | B 站 client_id 暴露在 A 站页面源码；PayPal 会以 A 域名作为 SDK 加载来源，与账号站点更不一致；与现有"A 站不加载 PayPal SDK"的安全前提冲突 |
| C. 保持跳转，只优化点击步骤 | 参见 `PAYPAL_CHECKOUT_UX_OPTIMIZATION.md` | 零合规增量 | 达不到"无感"要求 |

## 6. 决策记录

| 决策点 | 结论 |
|---|---|
| 镜像订单是否扣减 B 站库存 | 不扣减（`mirror_order_deduct_stock` 默认关闭） |
| 差价处理方式 | 独立调整行 `paypal_b_adjustment`，不改写商品单价 |
| 弃单镜像订单处置 | 会话过期 / 取消时转 CANCELLED，不物理删除 |
| embedded 失败时自动降级为 redirect | 开启（`embedded_fallback` 默认 `on`），细节见第 8 节 |
| B 站 `embedded_enabled` 粒度 | 全局开关 |

## 7. 与原生 Paypal 插件支付页的一致性

原生 `plugins/Paypal` 的支付页只输出一个 `#paypal-button-container` 容器，外层的订单号、金额、支付方式卡片由主题 `checkout/payment.blade.php` 渲染，按钮本身由 PayPal SDK 绘制。embedded 模式按同样的分工实现：

- A 站支付页仍由主题模板渲染卡片，PaypalA 的 `payment.blade.php` 只输出一个与原生插件同位置、同 class 的容器，容器内是 iframe；
- B 站 embedded 布局不输出品牌头、标题、摘要和页脚，`body` 透明背景、零内边距，只保留按钮容器与隐藏的提示区，因此买家看到的内容与原生插件相同：主题卡片加 PayPal 按钮；
- 按钮由 PayPal SDK 绘制，样式、可用的资金来源（PayPal、Pay Later、借记 / 信用卡按钮）取决于 B 站账号与 SDK 参数，与原生插件在同一账号下的表现一致；
- 错误与提示不在 iframe 内展示，而是 postMessage 给 A 站，由 A 站用主题的 `layer.alert` 弹出，与原生插件的提示方式一致；
- iframe 高度由 B 站页面通过 `ResizeObserver` 上报，A 站据此调整，避免出现滚动条或空白。

无法完全一致的两点：PayPal SDK 在跨站 iframe 内会强制弹窗完成登录（原生插件在同域下也是弹窗，差异仅在 Safari 可能多一次"允许弹出窗口"确认）；信用卡表单（Card Fields）原生插件没有，embedded 模式下是 B 站页面内的表单，样式按主题输入框对齐。

## 8. 自动降级为 redirect 的详细说明

### 8.1 触发条件

| 场景 | 检测方 | 检测方式 | 是否降级 |
|---|---|---|---|
| B 站未开启 `embedded_enabled`，或 B 站版本不支持 `presentation` | A 站服务端 | 创建会话返回 422 且错误码为 `embedded_not_allowed`，或响应缺少 `presentation` 字段 | 是，同一请求内改以 `presentation=redirect` 重建会话 |
| iframe 无法加载：B 站 `a_site_url` 与 A 站实际 origin 不一致（如 www 与裸域）导致 CSP 拦截、网络异常、B 站宕机 | A 站页面 | iframe 插入后在 `embedded_ready_timeout_seconds`（默认 8 秒）内未收到 B 站 `ready` 消息 | 是，顶层跳转到 `checkout_url` |
| B 站页面内 PayPal SDK 加载失败（被广告拦截器或网络阻断） | B 站页面 | 发送 `load_failed` 消息 | 是，顶层跳转到 `checkout_url`（跳转后 SDK 大概率仍加载失败，但买家至少能看到 B 站的明确错误与客服入口） |
| PayPal 弹窗被浏览器拦截 | PayPal SDK | SDK 自带重试提示 | 否，不属于内嵌失败 |
| 支付本身失败、被拒、取消 | B 站 | capture 结果 | 否，按现有错误处理 |

### 8.2 降级动作

会话的 `presentation` 不需要改。`frame-ancestors` 只限制被嵌入，不限制顶层打开；embedded 会话的 `finish` 页面检测到 `window.top === window` 时直接 302 到 A 站 `/paypal/return`，与 redirect 会话行为一致。因此降级只是 A 站执行一次 `window.top.location = checkout_url`，不产生新会话、不影响幂等与对账。

每次降级在 A 站写一条 `warning` 日志（订单号、原因、B 站响应），并在后台 PaypalA 设置页显示最近 24 小时降级次数，超过阈值时提示检查配置。

### 8.3 利弊

优点：

- 买家不会卡在空白 iframe 上，配置错误或 B 站临时不可用时仍能完成付款；
- 灰度上线安全，可以先开 embedded 观察降级率再决定是否全量；
- 实现简单，不引入新的会话状态。

缺点：

- 会掩盖配置错误。若 `a_site_url` 配错，所有买家都会在等待 8 秒后被跳到 B 站，运营可能长期没有察觉，因此日志与后台计数是必须项，不是可选项；
- 超时判定无法区分"慢"和"坏"，弱网买家可能在即将加载完成时被跳走，重新加载一次 B 站页面；
- 降级后买家会看到 B 站域名，与"无感"目标相悖。如果业务上宁可暂停收款也不能暴露 B 站，就应关闭自动降级。

### 8.4 推荐

开启自动降级，`embedded_fallback` 配置默认 `on`，同时提供 `off`：关闭时上述三种失败改为在 A 站原地提示"PayPal 暂不可用，请稍后重试或选择其他支付方式"，并保留"重试"按钮。超时秒数做成配置，默认 8 秒，范围 5–20。

## 9. 实施状态（2026-09-15）

阶段一（内嵌支付）与阶段二（镜像订单提前创建与差价）均已实现，本地已通过 `tests/Unit/PaypalBridgeTest.php` 60 个用例，并在事务内做过端到端模拟（B 站按会话的 CSP、finish 收口页、A 站支付页、状态接口、降级分支、镜像订单的建单 / 入账 / 取消 / 差价 / 库存）。尚未在真实沙盒双站联调，需按第 5.1 节的测试项在部署后验证，重点是 Safari、iOS 下跨站 iframe 内的 PayPal 弹窗。

与第 4 节设计的差异：

- B 站 `finish` 对内嵌会话不再 302，而是渲染 `embedded_finish` 收口页；页面被顶层打开时脚本自行跳回 A 站，因此降级后同一会话仍可走完。
- 降级计数按小时分桶存放在 database cache（`paypal_a:embedded_fallback:YmdH`），后台设置页汇总 24 小时，不新增表。
- 镜像订单的商品匹配顺序为映射 SKU → 原 SKU → 商品名称，只匹配 `products.active=1` 的商品；名称匹配取默认 SKU。
- `on_session` 建单的触发点在 PayPal Order 创建成功之后（会话进入 `pending`），创建结果未知或被拒绝的会话不建单。

部署要点：B 站执行 `php artisan migrate --force --path=plugins/PaypalB/Migrations/2026_09_15_000016_add_presentation_to_paypal_b_transactions.php`（或通过插件安装流程），两站 `php artisan view:clear`；先在 B 站开启“允许 A 站内嵌支付页”，再把 A 站“支付页形态”切到内嵌；镜像订单时机按需切到“建会话时”。
