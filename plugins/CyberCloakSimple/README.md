# CyberCloak Simple

CyberCloak Simple 是单数据库的轻量访问和内容映射插件。它适合“真实商品记录”和“展示商品记录”仍在同一个 MySQL 库、只需要在前台切换入口的场景。

## 功能边界

- `?key=...` 首次通过有效 key 进入真实模式，随后使用 `beike_simple_context` 签名 Cookie；每个 key 可配置 `value` 和独立 `expires_at`。
- IP 黑名单优先于 key；配置 IP 白名单后，未命中的请求保持展示模式。
- 国家/地区白名单支持多选，按客户端 IP 的 GeoIP 结果限制访问；未配置 GeoIP 数据库时回退到可信反向代理地区请求头。该规则先于 key 和 Cookie，未命中返回 404。
- 无 key/Cookie 的展示请求还可按 `Accept-Language` 白名单限制。
- 商品和分类映射使用 `real_id -> public_id`，展示模式的详情页只开放已映射入口。
- 商品、分类映射可在后台一键处理：已有展示目标保持不变，其余启用记录补齐同 ID 映射。
- 首页商品模块、Banner、图标和菜单中的商品/分类链接直接复用商品或分类映射；真实模式不修改原始装修配置。

## 安装和启用

1. 将 `plugins/CyberCloakSimple` 放入站点 `plugins` 目录。
2. 在后台“插件 -> 功能”安装并启用 `CyberCloak Simple`。
3. 不要同时启用完整 `CyberCloak`；两者都会接管商品和分类上下文，Simple 检测到完整插件后会主动跳过启动。
4. 在插件设置中保存 key、国家/地区规则和 JSON 映射，之后执行 `php artisan optimize:clear`。

## 配置示例

访问 key 可配置 `value` 和过期时间。运行时会将 `value` 转为 SHA-256 摘要：

```json
[
  {"id":"sales-key","value":"KEY_VALUE","status":"enabled","expires_at":"2026-12-31T23:59:59+08:00"}
]
```

商品、分类映射：

```json
[
  {"real_id":1,"public_id":101}
]
```

后台“商品与分类映射”面板提供两个一键按钮。它们会保留上方 JSON 中已配置的目标，并对其他启用记录补齐 `real_id == public_id` 的稳定映射。首页无需单独配置映射：

地区白名单支持一次选择多个国家/地区。配置 `国家/地区 GeoIP 数据库路径` 后，插件使用本地 `GeoLite2-Country.mmdb` 按客户端 IP 判定国家；未配置该文件时，地区请求头默认是 `CF-IPCountry`，只应由可信反向代理写入。地区白名单为空表示不限制，浏览器语言白名单为空也表示不限制。

## 验证

```powershell
php artisan test tests/Unit/CyberCloakSimple --colors=never
php artisan optimize:clear
```

本版本刻意不包含第二数据库、SKU 自动匹配、购物车/订单隔离、远程 IP 供应商和流量漏斗；这些场景继续使用完整 `CyberCloak` 插件。
