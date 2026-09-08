# 自定义邮件插件

本插件通过 BeikeShop 已有 Hook 扩展邮件场景，不修改 `beike/` 核心代码。

## 支持场景

- 客户注册成功
- 订单创建、待支付（含可直接进入支付页的链接）
- 待支付催款（仅在后台订单详情页手动发送，Offline Transfer 和 Western Union 可配置专用模板）
- 订单已支付、已发货、已完成、已取消、退款处理中
- 售后申请创建

后台进入 **插件 -> 自定义邮件**，启用场景后编辑主题、HTML 正文和是否发送给客户。每封已启用的自定义邮件都会发送到后台 **基础设置 -> 联系邮箱**；插件总设置中的“其他收件人”会额外收到全部已启用场景的邮件，多个地址可用逗号、分号或空格分隔。模板只支持白名单占位符（例如 `{{order_number}}`、`{{payment_url}}`），不会执行 Blade 或 PHP。`{{payment_url}}` 会生成当前订单的支付页地址，可用于 HTML 链接，例如 `<a href="{{payment_url}}">立即支付</a>`。订单模板还支持 `{{order_view_url}}`、`{{view_order_button_html}}`、`{{order_products_html}}`、`{{order_totals_html}}`、`{{shipping_address_html}}`、`{{shipping_telephone}}` 和 `{{payment_info_html}}`；其中 HTML 占位符由系统根据订单生成安全片段，直接放入正文即可。`{{payment_info_html}}` 只对 `offline_transfer` 和 `western_union` 订单生效，内容来自本插件配置页的支付信息 HTML 片段，并支持手动指定或按订单轮询。轮询模式可设置“每片段次数”，例如设置为 3 时，每个片段连续分配 3 个新订单后切换到下一个片段；同一订单的后续邮件会复用原片段。`{{view_order_button_html}}` 会生成与原生邮件一致的橙色 View Order 按钮，`{{order_view_url}}` 可用于自定义链接。`{{order_totals_html}}` 会显示原生邮件中的订单金额明细。若要替换系统自带注册、订单或退货邮件，请关闭系统邮件设置中对应的场景，避免重复发送。

邮件发送沿用系统邮件引擎和队列设置；订单类自动邮件在事务提交后发送，避免订单回滚产生误发。“订单待支付”和“待支付催款”仅对 `offline_transfer` 和 `western_union` 按 `payment_method_code` 匹配专用模板，其他支付方式（包括原生 PayPal）统一使用各自的通用模板。待支付催款不会自动发送，配置并启用后，在后台订单详情页点击“发送催款邮件”手动发送。插件保留核心注册、订单、退货邮件，不会替换或禁用核心通知。

当系统设置中没有启用“客户订单邮件”时，客户选择 `offline_transfer`（Offline Transfer）或 `western_union`（Western Union）提交订单后，插件会在订单事务提交成功后补发原生 `CustomerNewOrder` 邮件。该邮件直接复用系统原生 Mailable 和视图；如果系统客户订单邮件已启用，核心已经会发送相同邮件，插件会跳过以避免重复发送。

## 部署

首次安装同步整个 `plugins/CustomMail` 目录，在后台安装并启用，并执行 `php artisan migrate --force` 创建支付信息轮询和订单分配记录。更新代码后执行 `php artisan optimize:clear`。卸载插件时会删除其支付信息分配记录。
