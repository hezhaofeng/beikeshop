# PayPal Sandbox / Live Webhook 点击清单

这份清单的目标很简单：为 `PaypalB` 的每个自动收款账号，在对应环境的 PayPal 后台创建一个 Webhook，拿到 `Webhook ID` 后回填到 B 站账号配置。

注意：Sandbox 和 Live 必须分开创建、分开保存、分开验证，不能混用。

## 先准备

- [ ] B 站插件 `paypal_b` 已安装并启用。
- [ ] B 站后台已经配置好 `public_url`。
- [ ] 已经在 B 站“收款账号与交易管理”里创建了目标账号。
- [ ] 已知要绑定的 B 站账号 ID。
- [ ] 手头有对应环境的 PayPal 商户账号。

## 环境对照

| 环境 | 登录入口 | 进入位置 | 备注 |
| --- | --- | --- | --- |
| Sandbox | `https://developer.paypal.com/` | `Dashboard` -> `My Apps & Credentials` -> `Sandbox` | 用沙盒测试商户账号 |
| Live | `https://developer.paypal.com/` | `Dashboard` -> `My Apps & Credentials` -> `Live` | 用正式商户账号 |

## Sandbox 创建步骤

1. 打开 `https://developer.paypal.com/` 并登录。

2. 点击顶部或侧边的 `Dashboard`。

3. 进入 `My Apps & Credentials`。

4. 切换到 `Sandbox` 选项卡。

5. 点击 `Create App` 创建一个新的应用，或者直接点开已有的测试应用。

6. 在应用详情页里找到 `Webhooks` 区块，点击 `Add Webhook`。

7. 在 `Webhook URL` 输入 B 站地址，格式如下：

   ```text
   https://B站域名/api/paypal/webhooks/{账号ID}
   ```

8. 在事件列表里勾选下面这些事件：

   - `PAYMENT.CAPTURE.COMPLETED`
   - `PAYMENT.CAPTURE.REFUNDED`
   - `PAYMENT.CAPTURE.REVERSED`
   - `CUSTOMER.DISPUTE.CREATED`
   - `CUSTOMER.DISPUTE.UPDATED`
   - `CUSTOMER.DISPUTE.RESOLVED`
   - `CUSTOMER.DISPUTE.CANCELLED`

9. 如果页面提供搜索框，直接搜索 `PAYMENT.CAPTURE` 和 `CUSTOMER.DISPUTE`，逐项勾选即可。

10. 点击 `Save`。

11. 保存后复制页面显示的 `Webhook ID`。

12. 回到 B 站后台：`插件 -> paypal_b -> 收款账号与交易管理`。

13. 打开对应账号的编辑窗口，把刚才复制的 `Webhook ID` 填入 `webhook_id`。

14. 保存账号。

15. 最后把账号状态切到 `启用`。

16. 用 Sandbox 买家账号发起一笔测试支付，确认 B 站能收到 webhook 并写入交易记录。

## Live 创建步骤

Live 的点法和 Sandbox 完全一样，差别只有两个：

- 你切到的是 `Live` 选项卡，不是 `Sandbox`。
- 你使用的是正式商户账号和正式支付应用。

具体步骤如下：

1. 打开 `https://developer.paypal.com/` 并登录正式商户账号。

2. 点击 `Dashboard`。

3. 进入 `My Apps & Credentials`。

4. 切换到 `Live` 选项卡。

5. 创建或打开正式应用。

6. 在 `Webhooks` 区块点击 `Add Webhook`。

7. 输入正式 B 站地址：

   ```text
   https://B站域名/api/paypal/webhooks/{账号ID}
   ```

8. 勾选和 Sandbox 相同的一组事件：

   - `PAYMENT.CAPTURE.COMPLETED`
   - `PAYMENT.CAPTURE.REFUNDED`
   - `PAYMENT.CAPTURE.REVERSED`
   - `CUSTOMER.DISPUTE.CREATED`
   - `CUSTOMER.DISPUTE.UPDATED`
   - `CUSTOMER.DISPUTE.RESOLVED`
   - `CUSTOMER.DISPUTE.CANCELLED`

9. 点击 `Save`。

10. 复制生成的 `Webhook ID`。

11. 回到 B 站后台，把 Live 的 `Webhook ID` 填到对应正式账号的 `webhook_id` 字段。

12. 保存后启用账号。

13. 用正式环境走一笔小额订单验证回调。

## 回填到 B 站时的检查项

- [ ] `webhook_id` 和当前环境一致，Sandbox 不写到 Live，Live 不写到 Sandbox。
- [ ] 每个自动 API 账号都绑定了自己的 `Webhook ID`。
- [ ] B 站后台账号详情页能看到 `Webhook: https://.../api/paypal/webhooks/{账号ID}`。
- [ ] 账号启用前，Webhook 已经在 PayPal 后台创建完成。
- [ ] PayPal 后台勾选的事件类型覆盖支付完成、退款和争议。

## 常见错误

- 不要把 Sandbox 的 `Webhook ID` 填到 Live 账号里。
- 不要把 Live 的 `Webhook ID` 填到 Sandbox 账号里。
- 不要漏掉 `PAYMENT.CAPTURE.COMPLETED`，否则支付成功后的状态同步会断。
- 不要只勾退款事件，争议事件也要勾。
- 不要在 B 站还没配置 `public_url` 时去复制 webhook 地址。

## 验证建议

如果 PayPal 页面提供 `Send test`、`Webhook simulator` 或类似按钮，可以先发一条测试事件；最小验证事件建议用 `PAYMENT.CAPTURE.COMPLETED`。

如果测试事件能在 B 站后台看到对应 webhook 记录，说明地址、`Webhook ID` 和验签链路都基本正确。
