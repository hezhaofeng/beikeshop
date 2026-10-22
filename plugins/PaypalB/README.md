# PaypalB（B 站）

`PaypalB` 是 B 站的 PayPal 收款网关：既可作为 A/B 跨站支付的收款端，也可在关闭桥接后作为 B 站本地商城的 PayPal 支付方式独立使用。PayPal 凭据和收款账号池只保存在 B 站。

## 运行模式

- **A/B 跨站桥接**（配置项 `bridge_enabled` 开启）：接收 A 站 `PaypalA` 的签名建单请求，买家在 B 站支付页付款，结果以签名回调通知 A 站。开启时 A 站地址与两组桥接密钥必填；关闭时 A→B 的桥接接口一律拒绝。支付页形态由 A 站在建会话时声明并落库到 `presentation`：`redirect` 为顶层跳转（默认），`embedded` 为 A 站 iframe 内嵌（需本站开启 `embedded_enabled`）。
- **B 站本地支付**：插件启用后会把 `paypal_b` 注册为 B 站商城的支付方式，买家在本站订单支付页直接使用 PayPal Buttons 付款，支持游客订单；收款确认后通过 BeikeShop 状态机把本站订单置为已支付。本地支付不依赖桥接开关和 A 站密钥。
- 本地订单同一时间只有一个会话：有效期内复用，创建结果未知时沿用原账号与 `PayPal-Request-Id` 重试，过期后才新建；被 PayPal 明确拒绝的订单不会轮询到其他账号。

旧版本没有桥接开关：升级后只要 A 站地址或任一桥接密钥已填写，就视为桥接开启，行为不变。升级前已创建的会话 `presentation` 默认为 `redirect`，回跳与回调路径不变。

## 内嵌结账页

`embedded_enabled` 开启后，A 站可请求按 `embedded` 建会话。只有落库为 `embedded` 的跨站会话、且开关仍开启时，结账页才返回 `Content-Security-Policy: frame-ancestors <A 站 origin>`；其余会话、政策页一律 `'none'`。呈现方式只认会话记录，不读请求参数。

- 内嵌形态的布局隐藏品牌头、标题和商品摘要（A 站主题卡片已展示），收款主体披露与政策页脚保留，链接一律新窗口打开。
- 页面通过 `PaypalBEmbed.send()` 向 A 站 origin 发送 `ready`、`resize`、`completed`、`pending_capture`、`failed`、`cancelled`、`ended`、`load_failed` 事件；消息不携带金额或状态细节，A 站收到后一律经服务端签名接口拉取真实状态。
- 内嵌会话的 `finish` 不再 302 到 A 站，而是渲染一个只做 postMessage 的收口页；被顶层打开时（A 站已降级为跳转）脚本自行跳到 A 站回跳地址。关闭开关后，已建的内嵌会话立即无法嵌入，由 A 站加载超时降级兜底。
- A 站要求 `embedded` 而本站未允许时，建会话接口以 422 返回错误码 `presentation: embedded_not_allowed`，A 站据此自动降级为 `redirect` 重建。

## PayPal 钱包与信用卡

- 钱包付款只在 B 站顶层支付页加载 PayPal JS SDK Buttons；PayPal 官方 SDK 仍可能打开钱包登录/授权窗口。
- 信用卡付款在 B 站顶层页使用 PayPal Card Fields，卡号不会经过 A 站或 B 站服务器；支付成功后仍由 B 站服务端 Capture 并通过 Webhook/签名回调同步。
- Capture 返回待确认状态时，B 站支付页会短时查询交易状态；只有状态确认为 `completed` 才回到 A 站成功页。
- B 站和 A 站分别提供钱包/信用卡开关；对应方式需要两站同时开启。B 站信用卡默认关闭，避免升级后意外开放。
- 被轮询到的自动收款账号必须具备 PayPal Advanced Card Payments 资格；不具备资格时 Card Fields 会显示不可用，钱包付款仍可用。

## 收款账号

- 可同时启用 `企业 API 自动收款` 与 `个人卖家 API 自动收款` 账号：每个账号配置自己的 PayPal REST Client ID、Secret 和 Webhook ID，使用 Orders v2 自动创建和捕获。
- 新支付会话在满足币种、钱包/信用卡能力且未处于故障冷却的账号间轮询；轮询指针持久化并使用数据库锁保护。已创建、回跳、捕获、退款或待对账会话始终固定使用原账号。
- 旧的 `personal_api`、人工收款等账号类型仅保留历史数据兼容，不能新建、启用或继续支付；历史会话访问时会自动失效。

创建请求遇到连接故障、PayPal `408/409/425/429`、`5xx` 或响应异常时会保留原账号和同一个 `PayPal-Request-Id`，进入 `reconciling` 并等待同账号核对，不会切换账号制造第二个远端订单；凭据错误、合规拒绝和账号限制会立即停止。账号仍会校验币种、钱包/信用卡能力和原收款应用指纹。

同一 A 站订单不能并行创建多个 B 站支付会话。订单因 `4xx`、合规拒绝或账号配置问题失败后，即使 A 站换用新的支付引用，B 站也不会为该订单轮询其他账号。

## B 站商户责任与订单证据

B 后台必须配置真实收款主体名称和客服邮箱；退款政策、隐私政策和交易条款地址为选填，留空时支付页使用插件内置政策页，填写后必须位于 B 站同域地址并可直接访问。生产环境要求 HTTPS；`local`/`testing` 环境可使用 HTTP。消费者在 B 站支付页看到 A 站订单的真实商品、数量、金额、收货地址及上述责任信息。

B 会独立保存支付时点的真实订单快照以及不可变的 PayPal 商品投影；买家、账单地址、收货地址、运单号和签收证据使用 Laravel 应用密钥加密存储。A 站发货或修改运单后会通过 HMAC 接口同步；B 后台还可以记录签收时间、签收人和证明地址。实物订单提交 PayPal Orders API 时使用真实收货地址，不再使用 `NO_SHIPPING`。

商品明细模式只在 A 站 `PaypalA` 中配置。`a_order` 使用原商品名称和 SKU，`mapped` 使用 A 站按 CyberCloak 映射得到的名称和 SKU。B 始终保留真实 `order_items` 作为订单证据，并只把 `paypal_order_items` 发送给 PayPal；两份快照的商品 ID、数量、单价、行总额、费用/折扣、订单总额和币种必须逐项一致，只有名称和 SKU 可以不同。

## 镜像订单

跨站交易在本站生成一条 `payment_method_code=paypal_b` 的镜像订单供后台查看，订单号沿用 A 站订单号（撞号时改用 `-B2` 等后缀）。创建时机由 `mirror_order_timing` 决定：

- `on_completed`（默认，历史行为）：收款成功后直接以已支付写入，不经过状态机。
- `on_session`：A 站建会话成功（PayPal Order 已创建）时以待支付写入，A 站下单后即可在本站订单列表看到；收款后经状态机 UNPAID → PAID 并写入支付审计；会话过期、买家取消、PayPal 拒绝捕获或后台失效会话时自动 CANCELLED。同一 A 站订单只建一条镜像订单，重试会话复用。

商品行按本站目录分配：依次用发给 PayPal 的映射 SKU、A 站原 SKU、商品名称精确匹配本站在售商品；命中的写真实 `product_id` 并采用本站售价，未命中的沿用 A 站明细、`product_id=0`，并在交易上打 `mirror_unmapped` 标记（交易列表显示“未匹配商品”）。本站商品小计与 A 站实收的差额写成一条 `paypal_b_adjustment` 费用行（正为加价、负为折扣），订单总额始终等于 PayPal 实收；未匹配商品和 A 站真实商品名记入订单备注。

镜像订单经状态机流转时不向买家发送本站邮件（A 站已发），默认不扣减本站库存、不累计销量；`mirror_order_deduct_stock` 开启后才扣库存。历史镜像订单可用 `php artisan paypal-b:backfill-orders --with-adjustment` 补差价调整行（不回溯改商品单价）；`--dry-run` 只列出不写入。

## 提交给 PayPal 的订单信息

默认按完整明细建单：订单号（custom_id）、支付引用（reference_id / invoice_id）、商品行、金额拆分、收货地址、收款主体名称，实物订单使用 `SET_PROVIDED_ADDRESS`。

插件配置里的“兼容模式：精简提交”开启后，建单只提交三项：订单号（同时作为 invoice_id 和 custom_id，PayPal 交易详情与买家账单显示为发票编号）、金额、商品名称（以“; ”拼接，最长 127 字符）。不提交商品行、金额拆分、收款主体名称和商城收货地址；实物订单使用 `GET_FROM_FILE`，由买家在 PayPal 页面从自己的地址簿选择收货地址，虚拟订单使用 `NO_SHIPPING`。PayPal 记录的收货地址会显示在后台交易列表，并在订单历史的收款备注里注明；与商城订单地址不一致时标红提示，卖家保障只覆盖按 PayPal 地址发出的包裹。无论哪种格式，B 站都会先核对商品行、费用与总额一致，并完整保存订单快照。

所用格式记录在会话的 `bridge_request.paypal_payload_mode` 上，后台交易列表可见；切换开关只影响之后新建的会话，同账号重试沿用原格式。Webhook 按账号与 PayPal 订单号匹配，发票编号在完整明细模式必须等于支付引用、在兼容模式必须等于订单号；退款的发票编号与建单保持一致。兼容模式下同一订单号的多次付款尝试共用同一个发票编号，若 PayPal 账户开启了“阻止重复发票编号付款”，同一订单第二次成功付款会被 PayPal 拒绝。

## 待确认的收款

PayPal 可能受理捕获但暂不放款：风控审核（PENDING_REVIEW）、电子支票清算（ECHECK）、收款偏好要求人工接受等。此时 PayPal 后台显示待处理，插件的处理是：

- 立即记录 PayPal 捕获号，会话保持 `pending` 但不再过期；同一订单不能再建新会话，B 站结账页、本地支付页和 A 站支付页都只显示“PayPal 已受理，请勿重复付款”。买家在支付页轮询超时后会被送回原站点；本站订单会落到结账成功页并在标题下提示“PayPal 正在审核”，不再回到支付页。
- B 站同时把“已捕获待确认”回调给 A 站，A 站据此锁住订单。
- PayPal 放款后通过 `PAYMENT.CAPTURE.COMPLETED` 入账，即使已超过原会话有效期；`PAYMENT.CAPTURE.DENIED` 会把会话置为失败并通知 A 站。后台交易列表显示 PayPal 侧状态、待处理原因和卖家保障判定，并提供“同步 PayPal 状态”按钮。
- 计划任务 `paypal-b:sync-pending-captures` 每 30 分钟向 PayPal 查询仍待确认的捕获，Webhook 丢失时也能推进到最终状态；超过 45 天仍未确认的交给人工核对。

沙盒里如果所有付款都待处理，先到 developer.paypal.com 的 Sandbox Accounts 打开收款账号的 Settings，确认 Payment review 为 Off。

## PayPal 配置

沙盒和正式环境使用不同的 PayPal 应用凭据。每个自动账号都需要一个 PayPal Webhook，地址为：

`https://B站域名/api/paypal/webhooks/{账号ID}`

推荐让系统自动注册：新增账号时保持“保存后自动在 PayPal 注册 Webhook”勾选，或在账号列表点击“自动注册 Webhook”。系统会用该账号的 API 凭据调用 PayPal Webhooks 接口创建（或复用同地址的已有）Webhook，并把 Webhook ID 写回账号；前提是 B 站公网地址为 HTTPS。也可以在 PayPal 开发者后台手动创建后填写 Webhook ID，详见 `PAYPAL_WEBHOOK_SETUP.md`。

旧地址 `https://B站域名/api/paypal-b/webhooks/{账号ID}` 仍然可用，已登记的 Webhook 不必修改。Webhook 仍会通过 PayPal 官方签名接口验证，不能只依赖请求来源 IP。

## 安装与配置

1. 将 `plugins/PaypalB` 同步到 B 站 `plugins/PaypalB`，在后台插件页安装并启用 `paypal_b`。后台安装会执行本插件迁移，创建账号池和交易表；启用后它会自动出现在商城支付方式列表中，无需额外配置。
2. 在 B 站配置页填写 B 站根地址、收款主体、客服邮箱、Sandbox/正式环境、请求超时和账号失败冷却时间；退款政策、隐私政策、交易条款地址选填，留空使用内置政策页。需要跨站支付时再开启“启用 A/B 跨站支付”并填写 A 站地址和两组密钥；桥接密钥输入框留空保存普通配置时会保留旧值。
3. 打开“收款账号与交易管理”添加企业 API 或个人卖家 API 账号，填写 Client ID、Secret，保持“自动注册 Webhook”勾选并保存，系统会自动创建 Webhook 并回填 Webhook ID。未自动注册且未填写 Webhook ID 的账号会先以停用状态保存，之后可在列表中点击“自动注册 Webhook”。多个合格账号会按轮询顺序分配新支付会话。
4. 如需手动创建 Webhook，在对应 PayPal 环境使用地址 `https://B站域名/api/paypal/webhooks/{账号ID}`，并把 Webhook ID 保存到账号配置。B 站会通过 PayPal 官方 `verify-webhook-signature` 接口验签。

创建阶段不会因远端结果未知而换账号；必须由后台核对原账号上的 PayPal Order 或 Webhook 结果后再继续处理。

支付状态和退款结果先写入 B 站交易记录，再投递 `paypal_b` 队列；A 站回调失败会按退避策略重试。生产环境应运行 `php artisan queue:work --queue=paypal_b,default`，并通过每分钟一次的计划任务执行 `php artisan paypal-b:dispatch-callbacks`，用于补投递进程崩溃前未入队的 outbox 记录；`paypal-b:sync-pending-captures` 已一并注册在计划任务中。订单锁、桥接 nonce、OAuth token 和限流计数使用共享的 database cache，首次部署前需执行 `php artisan migrate --force` 创建 `cache` 与 `cache_locks` 表。

后台退款请求会进入 `paypal_b` 队列，并使用固定的 `PayPal-Request-Id` 幂等重试。`PAYMENT.CAPTURE.REFUNDED` 和 `PAYMENT.CAPTURE.REVERSED` Webhook 会核对退款号、金额和币种；部分退款只同步累计退款额，完整退款才会把 B 站交易置为取消并通知 A 站。缺少可核对金额的事件只进入待对账状态。

## 清理测试数据

`php artisan paypal-b:purge` 会按安全顺序清空插件产生的全部数据：先删跨站镜像订单及其商品、费用、历史和支付记录，再删支付会话、Webhook 事件和退款记录，重置轮询指针与账号故障计数，最后清掉 `paypal_b` 队列任务、失败任务和相关缓存。本站真实订单不会被删除，只清除它们在插件里的支付会话记录。

- `--dry-run` 只统计不删除，建议先执行一次；`--accounts` 同时删除收款账号池；`--settings` 同时删除插件配置，插件会回到未启用状态；`--force` 跳过确认，生产环境必须显式指定。
- 交易表保存着 PayPal 拒付举证快照和退款幂等号，有过真实收款的站点不要执行本命令。
- A 站若仍有引用旧会话的未支付测试订单，回跳时会一直提示结果确认中，需同步清理 A 站的 `paypal_a_transactions`。

## 安全边界

B 站只接受 A 站 HMAC 签名请求，并限制回调地址必须是配置的 A 站固定路径。金额和币种以 A 站签名快照为初始值，PayPal 创建、捕获、退款响应和 Webhook 还会再次逐分校验；任何结果不明的远端请求都只会保持待对账状态。

B 支付页默认返回 `Content-Security-Policy: frame-ancestors 'none'`，拒绝任何网站 iframe 嵌入；只有落库为 `embedded` 且后台仍允许内嵌的跨站会话才放开到配置的 A 站 origin，政策页永远不可嵌入。捕获接口使用绑定 B 站交易、PayPal Order ID 和 reference 的 HMAC 捕获令牌；订单状态仍以 PayPal Capture/Webhook 和 B 到 A 的服务端签名回调为准，内嵌页面发给 A 站的 postMessage 只是触发信号。
