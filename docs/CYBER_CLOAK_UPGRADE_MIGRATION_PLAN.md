# cyberCloak 已安装旧版升级与共享数据库迁移方案

## 1. 适用范围与结论

本文适用于以下场景：

- 测试环境与当前生产/开发环境共用同一主库和同一 `catalog_public` 展示库；
- 数据库中已经安装过旧版 `cyber_cloak` 插件；
- 需要把插件代码和配套核心代码升级到新版本；
- 当前仓库的目标插件版本以 `plugins/CyberCloak/config.json` 为准，目前记录为 `v1.2.0`。

核心结论：

1. 不卸载旧插件，不删除插件目录，不执行 `migrate:fresh`、`migrate:refresh`、`plugin:migrate-reset` 或 `plugin:migrate-fresh`。
2. 共享数据库只能由一个维护节点执行一次迁移；测试节点和生产/开发节点必须避免同时执行迁移或映射发布。
3. 两套应用必须先部署到同一份兼容代码，再执行数据库迁移；迁移窗口内应停止旧代码访问新代码依赖的字段。
4. 迁移完成后再进行商品导入、SKU 映射和路由映射发布。测试环境的映射、key、IP 规则和首页配置都会影响共用数据库中的其他环境。
5. 若条件允许，应尽快把测试环境改为独立的主库和 `catalog_public` 库；在完成隔离前，把所有测试数据操作按生产变更管理。

已安装旧版的更新不点击“卸载”后再“安装”，也不把后台首次安装按钮当作升级入口；升级只发布新代码并补跑未记录的增量迁移。

## 2. 当前代码与数据库边界

当前插件迁移文件按时间顺序为：

```text
2026_07_30_000001_create_catalog_tables.php
2026_07_30_000002_add_catalog_route_maps.php
2026_07_30_000003_add_cart_catalog_context.php
2026_07_30_000004_add_order_catalog_context.php
2026_07_30_000005_add_phase_six_management.php
2026_07_31_000006_add_catalog_category_paths.php
2026_07_31_000007_expand_catalog_product_schema.php
```

迁移的写入范围：

| 数据库 | 主要对象 | 说明 |
| --- | --- | --- |
| 主库 `mysql` | `catalog_mapping_versions`、`catalog_product_mappings`、`catalog_sku_mappings`、`catalog_route_maps`、购物车/收藏/订单扩展字段、IP 同步表、订单审核字段 | 业务订单、客户和历史数据仍在主库 |
| 展示库 `catalog_public` | `brands`、`categories`、`category_paths`、`products`、`product_categories`、`product_descriptions`、`product_skus` 及兼容字段 | 展示商品、分类、品牌和展示库存 |

迁移记录由 Laravel 的 `migrations` 表保存。由于两套环境共用主库，这张表也是共用的；一套环境执行成功后，另一套环境看到的就是同一迁移状态。

## 3. 发布前冻结与风险控制

### 3.1 必须先确认的变量

在变更单中填写并复核以下值：

```text
OLD_PLUGIN_VERSION=已部署旧版本
TARGET_PLUGIN_VERSION=v1.2.0
RELEASE_COMMIT=待发布代码提交号
MIGRATION_NODE=唯一执行数据库迁移的节点
PRIMARY_DATABASE=主库名称
PUBLIC_DATABASE=展示库名称
MAINTENANCE_WINDOW=维护窗口
```

实际密码、Token、Cookie key 仅通过部署平台的密钥变量注入，文档、Git 和命令历史均不留存。

### 3.2 当前工作区不适合直接打包

当前开发工作区存在大量已修改和未跟踪文件，包含 CyberCloak、核心模型、迁移和测试文件。发布前必须从经过审查的干净提交生成包，不应直接把整个工作区目录覆盖到共享环境。

发布包至少要记录：

- `git rev-parse HEAD`；
- `plugins/CyberCloak/config.json` 的版本；
- 发布包 SHA-256；
- `composer.lock` 是否发生变化；
- 是否修改 Dockerfile、PHP 扩展或前端构建产物。

## 4. 发布前检查

在测试环境和生产/开发环境分别执行只读检查，输出保存到变更记录。下面命令中的路径按实际容器路径调整。

```bash
php artisan --version
php -m | grep -E 'pdo_mysql|mbstring|openssl|json'
php artisan plugin:list --no-ansi
php artisan list cyber-cloak --no-ansi
php artisan migrate:status --path=plugins/CyberCloak/Migrations --no-ansi
```

确认两个数据库连接均可用：

```bash
php artisan tinker --execute="DB::connection()->getPdo(); DB::connection('catalog_public')->getPdo(); echo 'database-ok';"
```

如果 PowerShell 对内嵌 `$` 或引号进行展开，改用容器内 shell、临时 PHP 探针文件或数据库客户端执行，避免把探针语句误解析。

在主库执行：

```sql
SELECT migration, batch
FROM migrations
WHERE migration LIKE '2026_07_30_%'
   OR migration LIKE '2026_07_31_%'
ORDER BY migration;

SELECT value AS plugin_status
FROM settings
WHERE type = 'plugin' AND space = 'cyber_cloak' AND name = 'status';

SELECT status, COUNT(*) AS total
FROM catalog_sku_mappings
GROUP BY status;

SELECT route_type, status, COUNT(*) AS total
FROM catalog_route_maps
GROUP BY route_type, status;
```

在展示库执行：

```sql
SHOW TABLES LIKE 'category_paths';
SHOW COLUMNS FROM products;
SELECT COUNT(*) AS products FROM products;
SELECT COUNT(*) AS skus FROM product_skus;
```

若旧版本的迁移记录、展示库表或连接权限与预期不一致，先停止发布，补齐证据后再决定是否需要兼容迁移。

## 5. 备份与回滚点

在维护窗口开始前同时备份主库和展示库，备份成功后再继续。示例：

```bash
mysqldump --single-transaction --routines --triggers --events \
  --databases PRIMARY_DATABASE > primary_before_cyber_cloak_upgrade.sql

mysqldump --single-transaction --routines --triggers --events \
  --databases PUBLIC_DATABASE > public_before_cyber_cloak_upgrade.sql
```

额外保存映射和插件配置：

```bash
mysqldump --single-transaction --no-create-info PRIMARY_DATABASE \
  settings catalog_mapping_versions catalog_product_mappings \
  catalog_sku_mappings catalog_route_maps \
  > cyber_cloak_mapping_before_upgrade.sql

sha256sum primary_before_cyber_cloak_upgrade.sql \
  public_before_cyber_cloak_upgrade.sql \
  cyber_cloak_mapping_before_upgrade.sql
```

备份校验要求：文件大小大于 0、压缩包可列出、抽样恢复到临时库后能读取关键表。备份文件不得放在 Web 根目录。

## 6. 推荐发布顺序

### 阶段 A：停止并冻结两套应用

1. 通知维护窗口开始，暂停两套环境的后台操作、导入、映射发布和订单测试。
2. 将两套 Web 入口置于维护页，停止 Horizon、队列、定时任务和长驻 PHP 进程，避免旧代码在迁移过程中继续写入。
3. 不要在其中一套环境单独修改插件开关、key、IP 规则或首页配置；这些设置存于共享主库。

### 阶段 B：同时部署同一份代码

1. 在发布节点备份当前代码目录和 `.env`。
2. 将同一个 `RELEASE_COMMIT` 或同一个构建包部署到测试环境和生产/开发环境。
3. 保留两套环境各自的 `.env`、域名、日志和密钥，只替换代码及必要的构建产物。
4. 若 Docker Compose 使用 `APP_CODE_PATH` 绑定挂载，纯 PHP、迁移和插件代码更新通常不需要重建镜像，但必须重启 PHP-FPM、Horizon 和队列以清理 OPcache/常驻进程。
5. 若修改了 Dockerfile、Composer 依赖、PHP 扩展或系统包，再执行镜像重建；重建后仍需重启应用进程。

部署后在两套环境分别确认：

```bash
git rev-parse HEAD
cat plugins/CyberCloak/config.json
php -l plugins/CyberCloak/Bootstrap.php
php artisan optimize:clear
```

### 阶段 C：只在一个节点执行数据库迁移

确认两套应用已经使用同一代码后，在 `MIGRATION_NODE` 执行：

```bash
php artisan migrate:status --path=plugins/CyberCloak/Migrations --no-ansi
php artisan migrate --path=plugins/CyberCloak/Migrations --force --no-ansi
php artisan migrate:status --path=plugins/CyberCloak/Migrations --no-ansi
```

执行时不要增加 `--database=catalog_public`。这些迁移需要以主库作为迁移记录连接，同时在迁移文件内部显式操作 `catalog_public`；指定单一数据库连接会破坏这个边界。

预期结果：

- 已经执行过的旧迁移显示为 `Ran`，不会重复建表；
- 只补执行新版本缺失的迁移；
- `000006` 补建旧展示库缺失的 `category_paths`；
- `000007` 为旧展示库补齐 `products.weight`、`products.weight_class` 和必要的 `sales` 字段；
- 任何一步失败都停止后续操作，不在另一套环境重复执行。

迁移完成后检查主库和展示库表结构，再执行：

```bash
php artisan optimize:clear
```

### 阶段 D：恢复进程并确认插件状态

1. 重启 PHP-FPM、Horizon、队列和定时任务。
2. 检查 `cyber_cloak` 的共享启用状态；如果需要切换开关，只在一个后台入口操作并记录影响范围。
3. 确认命令已注册：

```bash
php artisan list cyber-cloak --no-ansi
```

4. 观察应用日志、队列失败和数据库错误至少一个完整观察周期。

## 7. 映射与展示数据升级

代码和结构升级不等于展示数据升级。除非本次版本明确要求重导，否则不要自动执行 `--truncate`，也不要删除旧映射。

推荐顺序：

```text
检查现有展示数据
    -> 导入文件 --dry-run
    -> 增量导入（如确有数据变更）
    -> 重建 SKU 草稿
    -> 处理 pending/conflict
    -> 发布新的 mapping version
    -> 重建商品路由
    -> 重建分类路由
    -> 清理应用/CDN缓存
```

命令模板：

```bash
php artisan cyber-cloak:import-catalog storage/app/catalog.json --dry-run
php artisan cyber-cloak:import-catalog storage/app/catalog.json
php artisan cyber-cloak:rebuild-sku-mappings --mode=exact
php artisan cyber-cloak:rebuild-route-mappings --type=product --mapping-version=MAPPING_VERSION
php artisan cyber-cloak:rebuild-route-mappings --type=category --mapping-version=MAPPING_VERSION
```

只有在测试和生产/开发确实要共享同一套展示关系时，才允许在共享数据库执行 `--mode=random --publish`。否则测试人员的随机映射会立即改变其他环境的商品和分类展示。映射发布后保留旧的 published 版本，验收完成前不要清理。

## 8. 验收清单

### 8.1 数据库与迁移

- 两套环境的代码提交号相同；
- 七个插件迁移的状态与目标版本一致；
- 主库和展示库均可连接；
- `category_paths`、`products.weight`、`products.weight_class` 等目标字段存在；
- 迁移没有新增重复表、重复索引或异常空值；
- 映射版本、路由版本和配置快照已记录。

### 8.2 前台访问

1. 无 Cookie 访问首页，确认进入 `public` 展示模式并返回 200。
2. 使用有效 key 首次访问，确认当前响应直接进入 `real` 模式并下发 `beike_context` Cookie。
3. 后续携带 Cookie 访问首页、分类、商品详情和前台 API，确认模式一致。
4. 清理 Cookie 后访问固定的 `/products/URL_ID` 和 `/categories/URL_ID`，确认 URL 不变且展示商品/分类可解析。
5. 检查首页 Banner、导航和商品模块没有生成 `/products/0`、`/categories/0` 或展示库主键直出链接。

### 8.3 交易与后台

- 真实购物车、展示购物车和收藏能够按来源读取；
- 订单商品快照包含商品库模式、库内 ID、履约 SKU 和映射版本；
- 支付回调/Webhook 不依赖前台 Cookie，仍按订单号读取主库；
- 后台 key、IP 规则、映射面板和订单审核页可打开；
- Horizon、队列和定时任务没有加载错误代码或跨请求上下文污染。

## 9. 回滚策略

### 9.1 仅代码回滚

如果是 PHP 逻辑或页面回归，而数据库迁移成功：

1. 两套环境同时恢复到上一个已验证代码包；
2. 保留新增字段、表和映射数据，不执行插件卸载或迁移回滚；
3. 执行 `php artisan optimize:clear`，重启 PHP-FPM/Horizon/队列；
4. 恢复旧的 published mapping version（如需要），再做冒烟验证。

新增字段采用默认值/可空字段时，旧代码通常可以继续运行；若本版本改变了不可逆的数据格式，必须使用完整备份恢复方案，不得只回滚代码。

### 9.2 迁移或数据回滚

如果迁移中断、表结构异常或映射数据错误：

1. 立即保持维护模式，停止两套环境写入；
2. 保存迁移输出、数据库错误、`migrations` 记录和当前表结构；
3. 优先恢复旧的映射版本或从映射备份恢复；
4. 只有确认备份和恢复点后，才按数据库恢复预案恢复主库与展示库；
5. 恢复后重新执行只读检查和完整验收。

不要把 `plugin:migrate-rollback` 当作常规升级回滚手段。该命令会调用迁移的 `down()`，可能删除购物车、订单扩展字段、映射表或展示库字段，且共享数据库会同时影响两个环境。

## 10. 共享数据库的长期整改

当前架构下，以下内容都是全局共享状态：

- 插件启用状态；
- `catalog_public` 商品、分类、SKU 和库存；
- SKU/商品/分类路由映射及 published version；
- key、IP 规则和漏斗配置；
- 首页装修中引用的真实商品和分类 ID；
- 订单、购物车、收藏和设置表。

因此测试环境不具备独立验证破坏性导入、随机映射、库存扣减、订单审核或插件开关的条件。建议后续拆分为：

```text
测试应用  -> test 主库 + test_catalog_public
生产/开发 -> prod 主库 + prod_catalog_public
```

在数据库完成隔离前，所有测试变更均需走同一套维护窗口、备份和回滚流程。
