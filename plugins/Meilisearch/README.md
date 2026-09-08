# BeikeShop Meilisearch 商品搜索插件

该插件直接通过项目已有的 Guzzle 调用 Meilisearch HTTP API，不依赖 Laravel Scout、Redis 或 Horizon。商品增量同步使用 Laravel `database` 队列，搜索服务不可用时可自动回退原有 MySQL 搜索。前台搜索页、搜索联想和 Trending Products 都由插件接管。

索引语言固定为 `zh_cn` 和 `en`；其他后台启用语言不会创建 Meilisearch 索引。

多词搜索默认使用 `frequency` 匹配策略，优先保留低频的品牌、球队或分类词，避免通用词（如 `Jersey`）导致结果退化为无关商品。

## CyberCloak 兼容

CyberCloak 把商品拆成真实库（`mysql`）和展示库（`catalog_public`）两个连接，同一个商品在两个库里是互不相干的自增 ID。插件据此做了三件事：

1. **索引按商品库隔离**。索引名为 `{前缀}_products_{catalog}_{语言}`，`meilisearch_index_states` 以 `(catalog, locale)` 为唯一键。前台检索时按当前请求所处的商品库选择索引，不会用真实库 ID 去展示库取商品。
2. **展示库索引遵守已发布 SKU 白名单**。构建展示库文档时复用 CyberCloak 的 `constrainToPublishedPublicSkus()`，没有任何已发布 SKU 的商品不进索引，未发布的展示 SKU 也不会写入文档，避免用内部 SKU 反查出对应的展示商品。
3. **商品数据一律回库读取**。索引 `displayedAttributes` 只有 `id`，搜索和联想都用 `ProductRepo` 回库取数据，因此与列表页共享同一套可见性规则。

CyberCloak 未安装或展示库连接未配置时，插件自动退化为只索引默认商品库，无需额外配置。

## 检索能力

搜索页和搜索联想都支持以下关键词，按权重从高到低排列：

| 关键词类型 | 支持 | 说明 |
| --- | --- | --- |
| 商品名称（全名或关键字） | ✅ | 分词后匹配，支持拼写容错 |
| SKU / 型号 | ✅ | 关闭拼写容错，另有精确匹配通道优先置顶 |
| 分类名称 | ✅ | 召回该分类下的商品，权重最低；可在后台关闭 |
| 品牌名称 | ❌ | 索引内只有 `brand_id`，未纳入可搜索字段 |

权重顺序由 `searchableAttributes` 决定：`name` → `sku` → `model` → `category_names`。因此搜索「手机」时，名称含「手机」的商品会排在「手机」分类下其他商品之前，分类词不会稀释名称精确匹配。

分类名称按当前语言取商品直接关联的分类及其 `category_paths` 中的全部父级分类。该开关属于索引级设置，**修改后需要重建索引才会生效**。展示库必须完成 `category_paths` 重建，否则展示库索引只能包含直接分类名称。

## SKU 搜索

- SKU 和型号关闭了拼写容错（`typoTolerance.disableOnAttributes`）。默认容错会让 `AB12345` 命中 `AB12346`，客户据此下单就会拿错货。
- 文档中额外保存规格化副本 `sku_exact`（去分隔符转大写），使 `ABC-123` 与 `abc123` 命中同一条记录，不受分词影响。
- 搜索首页和联想会先用 `sku_exact` 做精确过滤，把完全匹配 SKU 的商品提到最前，与原有 MySQL 搜索的排序语义保持一致。

## 环境配置

可以在插件后台设置，也可以使用环境变量：

```env
MEILISEARCH_HOST=http://meilisearch:7700
MEILISEARCH_KEY=应用专用密钥
MEILISEARCH_INDEX_PREFIX=beikeshop_test
MEILISEARCH_TIMEOUT=1
MEILISEARCH_BATCH_SIZE=300
MEILISEARCH_MYSQL_FALLBACK=true
QUEUE_CONNECTION=database
```

后台还可以分别设置搜索结果和 Trending Products 的默认排序：最新商品、浏览最多、销量最多、默认排序（`products.position` 正序）、最近更新或搜索相关度。搜索 URL 明确传入 `sort`/`order` 时优先使用 URL 排序。

**注意：** "接管前台搜索" 开关仅通过后台配置项控制，不支持环境变量覆盖。首次部署必须保持该开关关闭，完成全量索引和搜索验证后再在后台开启。

## 后台索引管理

插件编辑页下方提供索引管理面板，可以直接从数据库同步索引：

- 查看服务健康状态、各商品库和语言的活动索引、已索引文档数与数据库商品数的对比；
- 展示每个商品库和语言下的全部历史索引，当前使用的索引固定排在最前；
- 未使用的历史索引可直接删除，当前使用的索引不会提供删除操作；
- 选择商品库和语言后执行**从数据库同步索引**（全量重建）或**增量同步**；
- 全量重建会建立新索引并在完成后原子切换，重建期间前台继续使用旧索引；
- 任务进度实时轮询，队列不可用导致任务卡在排队状态时可终止任务释放锁。

索引任务由 `meilisearch` 队列执行，后台操作前需确保 Worker 正在运行：

```bash
php artisan queue:work --queue=meilisearch,default --sleep=3 --tries=3 --timeout=7200 --memory=256
```

## 命令行

```bash
php artisan meilisearch:health
php artisan meilisearch:reindex-products
php artisan meilisearch:reindex-products --catalog=public --locale=en
php artisan meilisearch:sync-changed
php artisan meilisearch:repair-products
```

`--catalog` 不传时处理当前站点全部可用商品库。通过后台安装插件时会自动执行插件迁移；直接将源码放入现有开发仓库时可手动执行 `php artisan plugin:migrate Meilisearch`。

## 定时任务

建议每分钟运行增量修复，并在每天低峰期执行完整修复：

```bash
php artisan meilisearch:sync-changed
php artisan meilisearch:repair-products
```

实时商品变更由观察器投递到 `meilisearch` 队列。批量状态更新等绕过 Eloquent 模型事件的操作会由增量修复命令补偿。

**CyberCloak 发布新的商品映射后，展示库的可见 SKU 集合会整体变化，需要重新同步展示库索引**（后台面板选择展示商品库执行全量同步，或运行 `php artisan meilisearch:reindex-products --catalog=public`）。映射发布走的是查询构造器批量写入，不触发模型事件，观察器无法感知。

## 搜索切换与回滚

确认索引正确后，在插件后台将”接管前台搜索”配置项设为开启。

发生异常时只需在后台关闭该开关，即刻恢复原有 MySQL 搜索。启用 MySQL 回退时，连接失败、超时、索引不存在和异常响应都会自动降级。带属性筛选（`attr` 参数）的请求始终走 MySQL，因为属性筛选没有下推到索引。

## 打包

```bash
php artisan plugin:zip Meilisearch
```

产物位于 `storage/zip/Meilisearch.zip`。
