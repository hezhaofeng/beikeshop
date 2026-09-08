# PaypalB（B 站）

`PaypalB` 是只负责收款的跨站网关，不注册为 B 站本地商城的支付方式。PayPal 凭据和收款账号池只保存在 B 站。

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

B 后台必须配置真实收款主体名称、客服邮箱、退款政策、隐私政策和交易条款，且这些页面必须位于 B 站同域地址并可直接访问。生产环境要求 HTTPS；`local`/`testing` 环境可使用 HTTP。消费者在 B 站支付页看到 A 站订单的真实商品、数量、金额、收货地址及上述责任信息。

B 会独立保存支付时点的真实订单快照以及不可变的 PayPal 商品投影；买家、账单地址、收货地址、运单号和签收证据使用 Laravel 应用密钥加密存储。A 站发货或修改运单后会通过 HMAC 接口同步；B 后台还可以记录签收时间、签收人和证明地址。实物订单提交 PayPal Orders API 时使用真实收货地址，不再使用 `NO_SHIPPING`。

商品明细模式只在 A 站 `PaypalA` 中配置。`a_order` 使用原商品名称和 SKU，`mapped` 使用 A 站按 CyberCloak 映射得到的名称和 SKU。B 始终保留真实 `order_items` 作为订单证据，并只把 `paypal_order_items` 发送给 PayPal；两份快照的商品 ID、数量、单价、行总额、费用/折扣、订单总额和币种必须逐项一致，只有名称和 SKU 可以不同。

## PayPal 配置

沙盒和正式环境使用不同的 PayPal 应用凭据。自动账号需要为对应账号创建 Webhook，并将地址设置为：

`https://B站域名/api/paypal-b/webhooks/{账号ID}`

Webhook 仍会通过 PayPal 官方签名接口验证，不能只依赖请求来源 IP。

## 安装与配置

1. 将 `plugins/PaypalB` 同步到 B 站 `plugins/PaypalB`，在后台插件页安装并启用 `paypal_b`。后台安装会执行本插件迁移，创建账号池和跨站交易表；B 站不需要把它配置成商城支付方式。
2. 在 B 站配置页填写 B/A 根地址、B 站收款主体、客服邮箱、退款政策、隐私政策、交易条款、两组密钥、Sandbox/正式环境、请求超时和账号失败冷却时间。桥接密钥输入框留空保存普通配置时会保留旧值。
3. 打开“收款账号与交易管理”添加企业 API 或个人卖家 API 账号，并分别填写 Client ID、Secret、Webhook ID；未填写 Webhook ID 时先停用保存，完成 PayPal Webhook 配置后再启用。多个合格账号会按轮询顺序分配新支付会话。
4. 每个自动账号在对应 PayPal 环境创建 Webhook，地址为 `https://B站域名/api/paypal-b/webhooks/{账号ID}`，并把 Webhook ID 保存到账号配置。B 站会通过 PayPal 官方 `verify-webhook-signature` 接口验签。

创建阶段不会因远端结果未知而换账号；必须由后台核对原账号上的 PayPal Order 或 Webhook 结果后再继续处理。

支付状态和退款结果先写入 B 站交易记录，再投递 `paypal_b` 队列；A 站回调失败会按退避策略重试。生产环境应运行 `php artisan queue:work --queue=paypal_b,default`，并通过每分钟一次的计划任务执行 `php artisan paypal-b:dispatch-callbacks`，用于补投递进程崩溃前未入队的 outbox 记录。订单锁、桥接 nonce、OAuth token 和限流计数使用共享的 database cache，首次部署前需执行 `php artisan migrate --force` 创建 `cache` 与 `cache_locks` 表。

后台退款请求会进入 `paypal_b` 队列，并使用固定的 `PayPal-Request-Id` 幂等重试。`PAYMENT.CAPTURE.REFUNDED` 和 `PAYMENT.CAPTURE.REVERSED` Webhook 会核对退款号、金额和币种；部分退款只同步累计退款额，完整退款才会把 B 站交易置为取消并通知 A 站。缺少可核对金额的事件只进入待对账状态。

## 安全边界

B 站只接受 A 站 HMAC 签名请求，并限制回调地址必须是配置的 A 站固定路径。金额和币种以 A 站签名快照为初始值，PayPal 创建、捕获、退款响应和 Webhook 还会再次逐分校验；任何结果不明的远端请求都只会保持待对账状态。

B 支付页返回 `Content-Security-Policy: frame-ancestors 'none'`，拒绝 A 站或其他网站 iframe 嵌入。捕获接口使用绑定 B 站交易、PayPal Order ID 和 reference 的 HMAC 捕获令牌；订单状态仍以 PayPal Capture/Webhook 和 B 到 A 的服务端签名回调为准。
