# cyberCloak 插件：同域双商品库部署与数据改造流程

> 当前代码状态：本文同时记录最终部署目标和阶段一至阶段六基础能力。当前版本已交付请求上下文、展示库基础表、JSON 导入、SKU 映射重建、前台浏览切库、购物车/收藏来源隔离、订单快照、履约库存链路和供应商 IP 本地缓存同步；支付插件回调与真实数据库仍需在沙箱中联调。

## 1. 部署拓扑

```text
客户端
  -> Cloudflare
  -> Nginx + Authenticated Origin Pull
  -> Laravel BeikeShop
       ├── 主库 mysql
       └── 展示商品库 catalog_public
```

主库负责运营数据，展示商品库负责展示商品、SKU、分类、品牌和库存数据。两个数据库必须使用兼容的表结构和字符集。

## 2. 部署前检查

### 应用代码

```bash
php artisan --version
php -m
php artisan route:list
php artisan plugin:list
```

确认 PHP 8.2、PDO MySQL、OpenSSL、JSON、Mbstring 和扩展依赖正常。

### 数据库

确认主库和展示库：

- 网络访问地址和端口明确。
- 数据库账号只授予业务所需权限。
- 两个数据库字符集使用 `utf8mb4`。
- 展示库已完成商品相关表迁移。
- SKU、规格、分类和品牌数据已完成初次导入。
- 有可恢复的数据库备份。

### Cloudflare 与 Nginx

确认：

- DNS 使用 Cloudflare 代理。
- 源站 443 开启 Authenticated Origin Pull。
- 防火墙仅允许 Cloudflare 官方网段访问源站。
- Nginx 已加载 Cloudflare 网段和 `real_ip` 配置。
- PHP-FPM 收到的是 Nginx 处理后的 `$remote_addr`。
- Cloudflare 对动态 HTML、购物车、结算和订单页面绕过缓存。
- 前台 API 请求使用与 HTML 相同的模式上下文，支付回调和 Webhook 使用订单号固定解析主库。

## 3. 环境变量

在 `.env` 中配置示例：

```dotenv
# 展示商品库
CATALOG_PUBLIC_DB_HOST=127.0.0.1
CATALOG_PUBLIC_DB_PORT=3306
CATALOG_PUBLIC_DB_DATABASE=beike_public
CATALOG_PUBLIC_DB_USERNAME=root
CATALOG_PUBLIC_DB_PASSWORD=

# cyberCloak 阶段一基础配置
CYBER_CLOAK_KEY_PARAMETER=key
CYBER_CLOAK_COOKIE_NAME=beike_context
CYBER_CLOAK_COOKIE_LIFETIME=120
CYBER_CLOAK_COOKIE_REFRESH=1440
# HTTPS 使用 true；纯 HTTP 使用 false。留空时按 APP_URL 自动判定。
CYBER_CLOAK_COOKIE_SECURE=true
CYBER_CLOAK_PREVENT_SHARED_CACHE=true
CYBER_CLOAK_TRUSTED_PROXY_IPS=
CYBER_CLOAK_CLOUDFLARE_ENABLED=true

# 阶段六供应商同步配置
CYBER_CLOAK_IP_PROVIDER_ENABLED=false
CYBER_CLOAK_IP_PROVIDER=http
CYBER_CLOAK_IP_PROVIDER_ENDPOINT=
CYBER_CLOAK_IP_PROVIDER_TOKEN=
CYBER_CLOAK_IP_PROVIDER_TIMEOUT=5
CYBER_CLOAK_IP_PROVIDER_CACHE_TTL=60
CYBER_CLOAK_IP_PROVIDER_FAIL_MODE=public
CYBER_CLOAK_IP_PROVIDER_ALLOW_EMPTY=false
CYBER_CLOAK_IP_PROVIDER_SCHEDULE=hourly
```

密码、供应商 Token 和证书使用服务器环境变量或密钥管理系统保存。文档、Git 和日志中不记录真实密钥。

若首页 URL 是 `http://`，必须将 `CYBER_CLOAK_COOKIE_SECURE=false`；否则浏览器会丢弃真实站 Cookie，后续分类、购物车等请求会回到展示库。首页 Banner 的图片文件不属于插件代码包，需同步 `public/image/catalog/` 下被装修配置引用的原图。

## 4. 数据库连接配置

在 `config/database.php` 增加展示库连接：

```php
'catalog_public' => [
    'driver'         => 'mysql',
    'host'           => env('CATALOG_PUBLIC_DB_HOST', env('DB_HOST', '127.0.0.1')),
    'port'           => env('CATALOG_PUBLIC_DB_PORT', env('DB_PORT', '3306')),
    'database'       => env('CATALOG_PUBLIC_DB_DATABASE', 'beike_public'),
    'username'       => env('CATALOG_PUBLIC_DB_USERNAME', env('DB_USERNAME', 'forge')),
    'password'       => env('CATALOG_PUBLIC_DB_PASSWORD', env('DB_PASSWORD', '')),
    'unix_socket'    => env('CATALOG_PUBLIC_DB_SOCKET', env('DB_SOCKET', '')),
    'charset'        => 'utf8mb4',
    'collation'      => 'utf8mb4_unicode_ci',
    'prefix'         => env('CATALOG_PUBLIC_DB_PREFIX', ''),
    'prefix_indexes' => true,
    'strict'         => true,
    'engine'         => 'InnoDB',
],
```

修改后执行：

```bash
php artisan config:clear
php artisan config:cache
```

## 5. 插件安装流程

```bash
php artisan plugin:list
php artisan migrate
php artisan optimize:clear
```

当前仓库未注册 `plugin:enable` 命令。请在后台插件管理中安装并启用 `cyberCloak`（插件编码 `cyber_cloak`），再执行配置清理；不要按未注册的命令行步骤操作。

## 6. 展示商品库初始化

阶段二已提供展示库导入和映射重建命令，阶段三增加稳定数字 URL 映射表；命令只有在插件启用后才会注册，最终以 `php artisan list` 的实际结果为准。

### 初始化结构

在后台插件管理中安装并启用 `cyberCloak`（插件编码 `cyber_cloak`）。项目的插件安装流程会自动执行 `Migrations` 目录，创建展示库基础表和主库映射表；安装后执行缓存清理：

```bash
php artisan optimize:clear
```

迁移通过 `catalog_public` 连接创建展示库表，通过默认连接创建主库映射表。当前仓库不使用 `php artisan migrate` 自动加载未安装插件的迁移。

### 导入展示商品

导入顺序建议为：

```text
语言和货币
分类
品牌
商品
商品描述
SKU和规格
商品分类关系
图片和资源路径
库存
```

导入后检查：

- 所有可售 SKU 都有唯一 SKU编码。
- 规格组合没有重复。
- 图片 URL可以从同域访问。
- 价格、税费、重量和物流数据完整。
- 分类和品牌关联完整。

## 7. SKU 映射构建

执行批量匹配：

```bash
php artisan cyber-cloak:rebuild-sku-mappings --mode=exact
```

匹配结果分为：

```text
confirmed  已确认
pending    待人工确认
conflict   存在冲突
disabled   已停用
```

名称、图片和价格相似度只能生成候选结果：

```bash
php artisan cyber-cloak:rebuild-sku-mappings --mode=candidate
```

两边商品不是同一批数据时，测试环境可以使用随机固定映射直接发布：

```bash
php artisan cyber-cloak:rebuild-sku-mappings --mode=random --publish
php artisan cyber-cloak:rebuild-route-mappings --type=product --mapping-version=VERSION
```

该模式首次匹配后把真实商品、Cloak 商品和 SKU 关系写入映射版本，后续重建优先复用历史关系；真实 SKU 仍保存为 `fulfillment_sku`。分类路由继续按分类名称和已匹配父分类生成，不会因为商品使用随机模式而随机伪造分类关系。

确认待处理映射并发布：

```bash
php artisan cyber-cloak:confirm-sku-mapping VERSION REAL_SKU_ID PUBLIC_SKU_ID --fulfillment-sku=FULFILLMENT_SKU
php artisan cyber-cloak:confirm-sku-mapping VERSION REAL_SKU_ID PUBLIC_SKU_ID --publish
```

正式启用前，`pending` 和 `conflict` 数量应为零，或已经明确配置了业务处理规则。

## 8. 数字 URL 映射构建

当前项目商品和分类 URL使用数字参数，例如：

```text
/products/123
/categories/8
```

数字参数作为对外路由身份保存，不要求等于展示库中的数据库主键。主库需要维护：

```text
catalog_route_maps
├── route_type
├── url_id
├── real_record_id
├── public_record_id
├── status
└── mapping_version
```

批量生成路由映射：

```bash
php artisan cyber-cloak:rebuild-route-mappings --type=product
php artisan cyber-cloak:rebuild-route-mappings --type=category
```

以上命令在插件安装启用后注册；未启用插件时不会出现在 `php artisan list` 中。

校验要求：

- 每个启用的真实商品 URL都有唯一 `url_id`。
- 每个可访问的展示 URL都有 `public_record_id`。
- 多个 `url_id` 可以指向同一个 `public_record_id`。
- 商品和分类映射不能混用 `route_type`。
- 停用映射返回 404，不跳转到其他数字 URL。
- 路由映射版本与 SKU映射版本一致。

发布顺序：

```text
导入展示商品
    -> 构建 SKU映射
    -> 确认 pending/conflict 并发布映射版本
    -> 构建数字 URL映射
    -> 校验缺失和冲突
    -> 清理应用缓存
    -> 清理 CDN缓存
    -> 开启展示模式
```

数字 URL 映射完成后，展示商品模型不能直接使用自身数据库 ID生成站内链接。商品资源应使用请求中的原始 `url_id`，保证 `/products/123` 在两种模式下仍然是同一个 URL。

## 9. IP 黑名单同步

供应商配置完成后执行首次同步：

```bash
php artisan cyber-cloak:test-ip-provider
php artisan cyber-cloak:sync-ip-provider --dry-run
php artisan cyber-cloak:sync-ip-provider
```

启用插件后，调度器会按 `ip_provider_schedule` 设置自动执行 `cyber-cloak:sync-ip-provider`。也可以手动执行：

```cron
php artisan schedule:run
```

请求过程使用本地数据库缓存。供应商接口超时不阻塞用户页面请求；`public` 失败策略在缓存过期或阶段六表缺失时强制进入展示模式，`keep` 策略保留仍未过期的旧缓存。

## 10. Nginx 与真实 IP

`cloudflare_ips.conf` 应包含 Cloudflare 官方网段：

```nginx
set_real_ip_from CLOUDFLARE_CIDR;
real_ip_header CF-Connecting-IP;
real_ip_recursive on;
```

PHP location 显式传递处理后的 IP：

```nginx
location ~ \.php$ {
    try_files $uri =404;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;

    # 将 Nginx real_ip 处理后的地址传给 Laravel。
    fastcgi_param REMOTE_ADDR $remote_addr;

    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
}
```

检查配置：

```bash
nginx -T | grep -E 'set_real_ip_from|real_ip_header|real_ip_recursive'
nginx -t
systemctl reload nginx
```

## 11. 缓存配置

Cloudflare Cache Rules 建议配置：

```text
请求带 beike_context Cookie：绕过缓存
请求带 key 参数：绕过缓存
购物车、收藏、结算、订单和支付回调：绕过缓存
HTML 商品页：绕过缓存或按模式隔离
CSS、JS、图片和字体：继续缓存
```

应用内部缓存 key 需要包含：

```text
catalog_mode
locale
currency
catalog_version
```

## 12. 验收和回滚

阶段一当前验收范围：无 key 默认展示模式、有效 key 进入真实上下文、签发 `beike_context`、Cookie 续期/失效、IP 黑名单优先级、query 参数清理、前台 HTML/API 上下文一致性、Horizon 路由隔离，以及请求结束状态清理。

### 阶段一验收

1. 无 key 访问首页，确认展示模式。
2. 使用有效 key 访问首页，确认当前响应就是真实模式，并包含 `Set-Cookie` 且没有 `Location`。
3. 使用 Cookie访问前台页面和 API，确认 HTML 与 API 的模式一致。
4. 检查页面链接没有继续携带 key。
5. 清理 Cookie后重新访问，确认恢复展示模式。
6. 用黑名单 IP携带有效 key访问，确认黑名单优先并清理旧 Cookie。
7. 访问 Horizon 路由，确认不会执行 `beike_context` 解析、签发或清理，也不会依据访问者 IP切换商品模式。
8. 检查请求完成后 `StoreContext` 已恢复为展示模式，长驻进程不会保留上一请求状态。

### 阶段二至阶段五验收

以下商品库、映射和交易步骤可在对应阶段执行；支付插件回调和真实库存联调需要启用具体插件后执行：

1. 在后台插件安装完成后，确认 `catalog_public` 中存在商品和 SKU 表、主库存在映射表。
2. 使用 `php artisan cyber-cloak:import-catalog storage/app/catalog.json --dry-run` 校验导入文件。
3. 商品相同或需要人工确认时执行导入和 `php artisan cyber-cloak:rebuild-sku-mappings --mode=exact`，检查 `pending` 与 `conflict`；两边商品不一致的测试环境可执行 `--mode=random --publish`，检查映射版本为 `published` 且后续重建保持相同展示商品。
4. 完成人工确认后使用 `--publish` 发布映射版本。
5. 访问真实 URL `/products/123`，记录真实商品 ID。
6. 清理 Cookie后再次访问 `/products/123`，确认展示商品 ID可以不同但 URL保持不变。
7. 验证展示商品链接仍然指向 `/products/123`，没有生成 `/products/7`。
8. 添加展示 SKU到购物车并完成结算。
9. 验证订单保存展示 SKU、履约 SKU、映射版本和商品快照。
10. 使用无 Cookie请求支付回调和 Webhook，确认仍能按订单号读取主库订单。
11. 验证支付、扣库存、发货和退款流程，确认库存不足时订单状态回滚。
12. 检查 Cloudflare 缓存不会混用两种模式。

回滚顺序：

```text
关闭插件开关
清理应用和 CDN缓存
恢复主库默认商品查询
保留新增订单字段和映射数据
根据备份恢复异常数据
```

回滚前保留：

- 主库备份。
- 展示库备份。
- SKU映射导出文件。
- key 和 IP规则导出文件。
- 当前插件配置。
