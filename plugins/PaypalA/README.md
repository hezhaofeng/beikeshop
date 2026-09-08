# PaypalA（A 站）

`PaypalA` 是安装在商品和订单所在站点的支付方式。它不保存任何 PayPal Client Secret，只保存本地订单和 B 站一次性支付会话的关系。

A 站设置可以分别开启 PayPal 钱包付款和信用卡/借记卡付款。信用卡选项只有在 B 站信用卡收款开关也开启、且被轮询到的企业或个人卖家 PayPal API 账号具备对应资格时才可用。

“订单商品明细模式”可选原商品明细或映射商品明细。原商品明细默认使用 A 站订单的名称和 SKU；映射模式依赖 CyberCloak 当前已发布、已确认的 SKU 映射，仅替换提交给 B/PayPal 的名称和 SKU。无论选择哪种模式，数量、单价、行总额、订单费用、币种、订单号和 A 站本地订单均以真实订单快照为准。

## 流程

1. A 站下单后创建 `paypal_a_transactions` 记录。
2. A 站使用 HMAC 请求签名把真实订单商品、PayPal 商品投影、金额明细、买家、收货地址、过期时间和固定回调地址发送到 B 站；B 保留真实订单证据，并再次核对两份商品行、费用、折扣和应付总额。
3. 浏览器顶层跳转 B 站支付页。PayPal 钱包使用 B 域名加载的 JS SDK Buttons，信用卡使用 Card Fields；授权后都由 B 站服务端使用 Orders v2 Capture。
4. B 站把统一状态以 HMAC 服务端回调发送回 A 站；买家回到 A 站确认路由时还会主动拉取一次状态作为补偿。
5. A 站只接受金额、币种、订单号、交易引用和签名全部匹配的 `completed` 状态，然后通过 BeikeShop 状态机写入支付记录并更新订单。
6. A 站新增、修改运单或更新发货状态后，通过签名接口把真实物流记录同步到 B 站。

同一 A 站订单同一时间只允许一个 B 站支付会话，首次选择的钱包或信用卡方式会固定到该会话，避免并行 PayPal Order 导致重复付款。

## 配置

在插件设置页填写 A/B 公网地址、两组不同的随机签名密钥、会话有效期和请求超时。生产环境的地址必须使用 HTTPS；`local`/`testing` 环境可使用 HTTP。A 站的 `request_signing_secret` 必须等于 B 站的 `a_request_verification_secret`；A 站的 `callback_verification_secret` 必须等于 B 站的 `a_callback_signing_secret`。

生产环境必须使用 HTTPS，A/B 不得使用同一域名和端口。建议使用 32 位以上随机值并分别保存于两个站点的密钥管理系统。

映射商品明细需要 CyberCloak 已启用，且订单中每个 SKU 都有当前已发布、已确认且仍有效的展示商品映射；缺少任一映射即停止支付，不会回退到原商品。PayPal Order 使用所选模式的名称和 SKU，但 B 站会校验商品 ID、数量、单价、行总额、费用和订单总额与 A 站真实订单快照完全一致；任一项不一致都会拒绝创建支付会话。

支付固定采用 B 站顶层托管模式，A 站不加载 PayPal SDK。PayPal 钱包仍可能打开官方登录或授权窗口；信用卡 3DS 也可能打开银行验证页面，这些官方验证流程不能由插件关闭。

## 安装与验证

1. 将 `plugins/PaypalA` 同步到 A 站 `plugins/PaypalA`，在后台插件页安装并启用 `paypal_a`。后台安装会执行本插件迁移，创建 `paypal_a_transactions` 表。
2. 在 A 站配置页填写 A/B 根地址和两组密钥。密码框留空保存普通配置时会保留已保存密钥。
3. 确认 B 站已安装并启用 `paypal_b`，且 B 站的 `a_request_verification_secret`、`a_callback_signing_secret` 分别与 A 站对应项一致。
4. 用未支付订单发起一次 Sandbox 付款，核对 A 站订单金额、币种、订单号和支付记录均由本地订单产生；不要把 PayPal 返回的订单号当成本地订单号。

A 站必须能从公网接收 `POST /api/paypal-a/bridge/callback`。如果 B 站暂时无法回调，买家回跳 A 站时会主动查询一次；后台仍可在 B 站交易页重试回调。

## 履约证据同步

订单发货或状态流转后，A 站会把运单证据同步到 B 站，供 B 站应对 PayPal 拒付举证。同步走 outbox 模式：先在 `paypal_a_transactions` 上标记待发送，再投递 `paypal_a` 队列，失败按 60/300/900/3600/7200 秒退避重试。发货数据未变化时不会重复发送。

生产环境二选一即可，两者同时配置最稳妥：

- 运行 `php artisan queue:work --queue=paypal_a,default`，发货后秒级同步；
- 配置 Laravel 计划任务（`php artisan schedule:run` 每分钟），`paypal-a:dispatch-fulfillment` 会每 5 分钟**同步**补投一次，不依赖队列 worker。

只配置队列而没有计划任务时，worker 崩溃期间的证据会滞留；只配置计划任务时同步延迟最多 5 分钟。排查可查 `paypal_a_transactions` 的 `fulfillment_status`、`fulfillment_attempts` 和 `fulfillment_last_error`。
