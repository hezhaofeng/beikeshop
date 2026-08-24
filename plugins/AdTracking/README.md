# 平台广告追踪与转化事件

`AdTracking` 是 BeikeShop 的多平台广告归因插件，使用现有插件 Hook 接入，不修改核心结账状态机。

## 已实现功能

- Facebook Pixel 标准事件和 Conversion API Purchase，支持单播、多播、主备三种 Pixel 分发模式；Meta、GA4 可分别回传订单状态变更；
- Google Ads Conversion、Google Tag Manager 和 GA4 Measurement Protocol；
- TikTok Pixel 和 Events API Purchase；
- Twitter / X Pixel、Pinterest Tag；
- PageView、ViewContent、AddToCart、InitiateCheckout、Purchase、Search；
- 标准事件携带页面路径、来源页、商品品牌/分类/SKU、库存、加购行金额、结账商品数量和订单商品行金额等上下文；
- 服务端逐次上报待支付、已支付、已发货、已完成、退款中、已取消等订单状态，取消和退款不会被计为 Purchase；
- UTM、GCLID、FBCLID、TTCLID、TWCLID、MSCLKID、EPik 等参数捕获；
- 订单来源、媒介、活动、Click ID、落地页和来源页写入订单；
- 自定义 GET/POST Postback，可配置请求头和触发事件；
- 前端 opt-in 同意模式，服务端回传同步遵守同意状态；
- 中文和英文后台设置界面。

## 安装

1. 将 `AdTracking` 目录放入 `plugins/`。
2. 在后台插件管理中安装并启用“平台广告追踪与转化事件”。
3. 配置平台 ID 和服务端 Token，保存后执行 `php artisan optimize:clear`。
4. 使用浏览器开发者工具检查平台脚本和事件；订单详情页可查看归因结果。

## 配置文档

- [AdTracking 后台配置对照表](./CONFIGURATION.md)

Facebook / Meta 的多个 Pixel ID 可在后台按行填写，也支持使用逗号、空格或分号分隔。默认推荐 `failover` 主备模式：浏览器端只加载第一行主 Pixel，服务端 Conversion API 会按顺序尝试，主失败后再切到备用 Pixel。若切换为 `broadcast` 多播模式，则会向全部 Pixel 同时发送，因此 Token 需要具备对应数据源权限。

## 隐私说明

启用 `opt_in` 时，平台脚本和服务端事件默认等待 `beike_ad_consent=granted`。前端可调用：

```js
window.beikeAdTracking.setConsent(true);
window.beikeAdTracking.setConsent(false);
```

服务端事件日志只保存订单号、金额、来源和活动，不保存明文邮箱和电话；Facebook 用户字段使用 SHA-256。
