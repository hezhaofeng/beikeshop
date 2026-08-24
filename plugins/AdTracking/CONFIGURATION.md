# AdTracking 后台配置对照表

本文档适用于 BeikeShop `AdTracking` 插件当前版本。

插件已经内置 GA4 `gtag.js`、Meta Pixel、TikTok Pixel、Twitter/X Pixel 和 Pinterest Tag 的浏览器端加载逻辑。配置完成后，不需要再把相同平台的手写脚本重复添加到主题或后台自定义代码中。

## 一、基础设置

| 后台显示名称 | 字段 key | 推荐填写 | 是否必填 | 说明 |
| --- | --- | --- | --- | --- |
| 启用插件 | `status` | 开 | 是 | 插件总开关。 |
| 隐私同意模式 | `consent_mode` | `always` 或 `opt_in` | 是 | `always` 立即加载；`opt_in` 等待用户同意后加载。 |
| 标准事件 | `enabled_events[]` | `PageView`、`ViewContent`、`AddToCart`、`InitiateCheckout`、`Purchase`、`Search` | 否 | 控制哪些标准事件允许发送。 |
| 订单状态上报 | `order_status_events` | 开 | 否 | 服务端分别回传每次订单状态迁移，不影响六个浏览器标准事件。 |

如果希望行为接近手写的 GA4 / Meta `PageView` 代码，使用：

```text
consent_mode = always
enabled_events[] = PageView
```

如果要启用完整电商追踪，建议勾选全部标准事件。

六个标准事件会按以下粒度发送参数：

| 事件 | 重点参数 |
| --- | --- |
| `PageView` | `page_location`、`page_path`、`page_referrer`、`page_title`、`language` |
| `ViewContent` | 商品 ID、SKU/变体、名称、品牌、分类、库存状态、价格和 `items` |
| `AddToCart` | SKU、商品名称、单价 `unit_price`、数量、总价 `value` 和 `items` |
| `InitiateCheckout` | 商品/SKU ID、行金额、总件数、去重商品数和结账阶段 |
| `Purchase` | 订单号、交易号、总件数、去重商品数、商品变体和行金额 |
| `Search` | `search_string`、`search_term`、搜索路径 |

订单状态事件的固定名称为 `OrderStatus`（GA4 为 `order_status`），字段包含 `order_status` 和 `order_result`。当前核心会记录 `created`，并在状态迁移中覆盖 `unpaid`、`paid`、`shipped`、`completed`、`refunding`、`cancelled`；其中 `paid`、`shipped`、`completed` 的结果为 `success`，取消和退款保持各自语义，不会伪装成成交或失败。事件会在订单数据库事务提交后才发送。BeikeShop 当前状态机没有独立的 `failed` 状态，支付失败但未发生状态迁移时不会产生虚构状态事件。

## 二、Facebook / Meta

| 后台显示名称 | 字段 key | 示例 | 是否必填 | 说明 |
| --- | --- | --- | --- | --- |
| 启用该平台 | `facebook_enabled` | 开 | 是 | Meta 平台总开关。 |
| 启用浏览器事件 | `facebook_browser` | 开 | 是 | 加载 Meta Pixel 并发送浏览器事件。 |
| 启用服务端回传 | `facebook_server` | 开 / 关 | 按需 | 开启 Conversion API 服务端 Purchase 和 `OrderStatus` 回传。 |
| Pixel 分发模式 | `facebook_dispatch_mode` | `failover` | 是 | `single` 只发第一行主 Pixel；`broadcast` 向全部 Pixel 同时发送；`failover` 浏览器只加载第一行主 Pixel，服务端 CAPI 主失败后按顺序切到备用 Pixel。 |
| Pixel IDs | `facebook_pixel_ids` | 每行一个，如 `1625473505149626` | 浏览器追踪必填 | 支持多个 ID，每行一个，也支持逗号、空格或分号分隔；`single` 和 `failover` 模式下第一行视为主 Pixel。旧配置 `facebook_pixel_id` 仍兼容。 |
| Conversion API Token | `facebook_access_token` | `META_CAPI_TOKEN` | 服务端回传必填 | 从 Meta Events Manager 获取。 |

官方获取入口：

- [Meta Pixel 官方文档](https://developers.facebook.com/docs/meta-pixel/get-started/)
- [Meta Conversion API 官方文档](https://developers.facebook.com/docs/marketing-api/conversions-api/)

浏览器端配置：

```text
facebook_enabled = 开
facebook_browser = 开
facebook_server = 关
facebook_dispatch_mode = failover
facebook_pixel_ids =
1625473505149626
987654321000000
```

浏览器端 + 服务端配置：

```text
facebook_enabled = 开
facebook_browser = 开
facebook_server = 开
facebook_dispatch_mode = failover
facebook_pixel_ids =
1625473505149626
987654321000000
facebook_access_token = META_CAPI_TOKEN
```

模式建议：

- `single`：只使用第一行主 Pixel，数据最简单。
- `broadcast`：适合需要把同一事件同时发送给多个广告账户的场景。
- `failover`：推荐默认值。浏览器端只加载第一行主 Pixel；服务端 Purchase 先发主 Pixel，失败时再切到后续备用 Pixel。

注意：

- `failover` 的自动切换主要发生在服务端 CAPI；浏览器端无法可靠判断某个 Pixel ID 是否在 Meta 后台被判无效，因此浏览器只会加载第一行主 Pixel。
- 如果多个 Pixel 对应不同数据源，`facebook_access_token` 需要对这些数据源具备发送权限，否则备用 Pixel 的 CAPI 仍会失败。

## 三、Google Analytics 4

| 后台显示名称 | 字段 key | 示例 | 是否必填 | 说明 |
| --- | --- | --- | --- | --- |
| 启用该平台 | `ga4_enabled` | 开 | 是 | GA4 平台总开关。 |
| 启用浏览器事件 | `ga4_browser` | 开 | 浏览器追踪必填 | 加载 `gtag.js` 并发送 GA4 事件。 |
| 启用服务端回传 | `ga4_server` | 开 / 关 | 按需 | 开启 Measurement Protocol 服务端 Purchase 和 `order_status` 回传。 |
| GA4 Measurement ID | `ga4_measurement_id` | `G-P7DN946R3E` | 浏览器追踪必填 | 对应 `gtag('config', 'G-...')`。 |
| GA4 API Secret | `ga4_api_secret` | `GA4_API_SECRET` | 服务端回传必填 | 从 GA4 Data Stream 获取。 |

官方获取入口：

- [GA4 管理后台](https://analytics.google.com/analytics/web/)
- [GA4 Measurement Protocol 官方文档](https://developers.google.com/analytics/devguides/collection/protocol/ga4)

浏览器端配置：

```text
ga4_enabled = 开
ga4_browser = 开
ga4_server = 关
ga4_measurement_id = G-P7DN946R3E
```

浏览器端 + 服务端配置：

```text
ga4_enabled = 开
ga4_browser = 开
ga4_server = 开
ga4_measurement_id = G-P7DN946R3E
ga4_api_secret = GA4_API_SECRET
```

## 四、Google Ads / GTM

| 后台显示名称 | 字段 key | 示例 | 是否必填 | 说明 |
| --- | --- | --- | --- | --- |
| 启用该平台 | `google_ads_enabled` | 开 | 按需 | Google Ads / GTM 平台总开关。 |
| 启用浏览器事件 | `google_ads_browser` | 开 | 使用 Ads 转化时必填 | 通过 gtag 发送 Ads 事件。 |
| Google Ads Conversion ID | `google_ads_id` | `AW-123456789` | Ads 转化必填 | 注意不是 GA4 的 `G-...`。 |
| Google Ads Conversion Label | `google_ads_label` | `AbCdEfGhIjK` | Purchase 转化必填 | 对应 Google Ads 转化操作的 Label。 |
| Google Tag Manager ID | `google_tag_manager_id` | `GTM-XXXXXXX` | 使用 GTM 时必填 | 可选，用于加载 GTM 并推送 `beike_*` 事件。 |

官方获取入口：

- [Google Ads 转化跟踪官方说明](https://support.google.com/google-ads/answer/1722022?hl=zh-Hans)
- [Google Tag Manager 官方后台](https://tagmanager.google.com/)

当前版本 Google Ads 使用浏览器端 gtag / GTM 转化追踪，没有独立的 Google Ads 服务端回传开关。

## 五、TikTok

| 后台显示名称 | 字段 key | 示例 | 是否必填 | 说明 |
| --- | --- | --- | --- | --- |
| 启用该平台 | `tiktok_enabled` | 开 | 是 | TikTok 平台总开关。 |
| 启用浏览器事件 | `tiktok_browser` | 开 | Pixel 追踪必填 | 加载 TikTok Pixel。 |
| 启用服务端回传 | `tiktok_server` | 开 / 关 | 按需 | 开启 Events API 服务端 Purchase 回传。 |
| TikTok Pixel ID | `tiktok_pixel_id` | `TT-PIXEL-ID` | 浏览器追踪必填 | TikTok Pixel 标识。 |
| TikTok Events API Token | `tiktok_access_token` | `TIKTOK_ACCESS_TOKEN` | 服务端回传必填 | TikTok Events API token。 |

官方获取入口：

- [TikTok Pixel 官方说明](https://ads.tiktok.com/help/article/tiktok-pixel)
- [TikTok Events API 官方说明](https://ads.tiktok.com/help/article/events-api)

## 六、Twitter / X

| 后台显示名称 | 字段 key | 示例 | 是否必填 | 说明 |
| --- | --- | --- | --- | --- |
| 启用该平台 | `twitter_enabled` | 开 | 是 | Twitter / X 平台总开关。 |
| 启用浏览器事件 | `twitter_browser` | 开 | 是 | 加载 X Pixel 并发送浏览器事件。 |
| Twitter / X Pixel ID | `twitter_pixel_id` | `TW-PIXEL-ID` | 浏览器追踪必填 | X Ads Pixel 标识。 |

官方获取入口：

- [X 网站转化追踪官方说明](https://business.x.com/en/help/campaign-measurement-and-analytics/conversion-tracking-for-websites)

当前版本 Twitter / X 只支持浏览器端 Pixel 追踪。

## 七、Pinterest

| 后台显示名称 | 字段 key | 示例 | 是否必填 | 说明 |
| --- | --- | --- | --- | --- |
| 启用该平台 | `pinterest_enabled` | 开 | 是 | Pinterest 平台总开关。 |
| 启用浏览器事件 | `pinterest_browser` | 开 | 是 | 加载 Pinterest Tag 并发送浏览器事件。 |
| Pinterest Tag ID | `pinterest_tag_id` | `PINTEREST-TAG-ID` | 浏览器追踪必填 | Pinterest Tag 标识。 |

官方获取入口：

- [Pinterest Tag 官方说明](https://help.pinterest.com/en/business/article/install-the-pinterest-tag)

当前版本 Pinterest 只支持浏览器端 Tag 追踪。

TikTok Events API 在当前插件中仅使用 `CompletePayment` 映射 Purchase；为避免将取消、退款等状态误报为支付完成，订单状态事件只发送到 Meta CAPI、GA4 Measurement Protocol 和按需启用的 Postback。

## 八、自定义 Postback

| 后台显示名称 | 字段 key | 示例 | 是否必填 | 说明 |
| --- | --- | --- | --- | --- |
| 启用该平台 | `postback_enabled` | 开 | 按需 | 自定义回传总开关。 |
| 启用服务端回传 | `postback_server` | 开 | 按需 | 允许服务端发送 Postback。 |
| Postback URL | `postback_url` | `https://affiliate.example/postback` | 使用 Postback 时必填 | 第三方联盟或归因平台地址。 |
| 请求方法 | `postback_method` | `POST` / `GET` | 使用 Postback 时必填 | 请求方式。 |
| 触发事件 | `postback_events` | `Purchase` | 使用 Postback 时必填 | 多个事件使用逗号分隔；添加 `OrderStatus` 可接收每次状态变更。 |
| 请求头 JSON | `postback_headers` | `{"Authorization":"Bearer TOKEN"}` | 否 | 自定义 HTTP 请求头。 |

启用 `OrderStatus` 后，Postback 额外携带 `order_status` 和 `order_result`；事件 ID 会包含状态代码，因此同一订单的不同状态不会相互幂等，但相同状态的重复回调只会发送一次。

## 九、你当前 GA4 + Meta 的推荐配置

如果只需要你之前提供的 GA4 和 Meta 两段代码的等价功能，可以填写：

```text
status = 开
consent_mode = always

enabled_events[] = PageView
```

Facebook：

```text
facebook_enabled = 开
facebook_browser = 开
facebook_server = 关
facebook_dispatch_mode = failover
facebook_pixel_ids = 1625473505149626
facebook_access_token = 留空
```

GA4：

```text
ga4_enabled = 开
ga4_browser = 开
ga4_server = 关
ga4_measurement_id = G-P7DN946R3E
ga4_api_secret = 留空
```

如果还需要商品浏览、加购、结账和购买事件，把 `enabled_events[]` 中的其他标准事件一起勾选即可。

## 十、避免重复统计

AdTracking 已经会自动加载和执行：

```html
<!-- gtag.js -->
<!-- Meta Pixel -->
```

因此不要再把相同 `G-...` 和 Meta Pixel ID 的手写脚本同时添加到：

- 主题 `header.blade.php`
- 后台自定义头部代码
- GTM 中重复的 GA4 / Meta 基础标签

否则可能造成 `PageView`、`Purchase` 等事件重复上报。

如果必须保留主题中的手写代码，请关闭插件对应的浏览器通道，只保留插件服务端回传：

```text
ga4_browser = 关
facebook_browser = 关
ga4_server = 开
facebook_server = 开
```

前提是已经填写对应的 API Secret 和 Conversion API Token。

## 十一、隐私同意模式

| 模式 | 行为 |
| --- | --- |
| `always` | 页面加载后立即加载平台脚本并发送允许的事件。 |
| `opt_in` | 用户点击同意后加载平台脚本并发送事件；拒绝后不发送浏览器事件。 |

服务端 Purchase 回传也会读取同意状态。启用 GDPR / Cookie 合规时，推荐使用 `opt_in`。
