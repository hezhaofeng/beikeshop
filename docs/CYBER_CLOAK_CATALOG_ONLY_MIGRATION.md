# cyberCloak 全新测试环境商品、分类和首页映射迁移说明

## 1. 目的与适用范围

本文用于将当前 `cyberCloak` 代码部署到全新的 BeikeShop 测试环境。目标是保留同域双商品库、商品映射、分类映射和首页映射能力，同时保持 BeikeShop 原生订单、购物车、收藏、结算、库存和订单状态机流程。

适用前提：

- 测试环境已安装与生产同版本的 BeikeShop；
- 主库已具备真实商品、分类、首页装修和原生订单数据；
- `catalog_public` 是独立展示商品库，初始状态为空；
- 测试环境尚未安装旧版 CyberCloak 订单/购物车隔离功能；
- 当前插件版本以 `plugins/CyberCloak/config.json` 为准。

本文不适用于已安装旧版 CyberCloak 且数据库存在 `catalog_mode`、`catalog_product_id`、`fulfillment_sku` 等字段的升级场景。该场景使用 [CYBER_CLOAK_UPGRADE_MIGRATION_PLAN.md](CYBER_CLOAK_UPGRADE_MIGRATION_PLAN.md)。

## 2. 数据和业务边界

```text
主库 mysql
  - 客户、购物车、收藏、订单、支付、结算、库存、售后
  - 真实商品、分类、品牌和首页装修 base.design_setting
  - 商品/SKU 映射版本和稳定 URL 映射

展示库 catalog_public
  - 展示品牌、分类、分类路径、商品、SKU、库存

CyberCloak
  - real/public 请求上下文选择
  - 商品、分类、SKU、首页商品链接、Banner 图片映射
```

订单、购物车、收藏、结算、支付回调、库存扣减和订单状态机始终使用主库原生 BeikeShop 流程。CyberCloak 不向这些流程写入展示库商品身份字段。

## 3. 发布包清单

以下路径均相对于项目根目录。

### 3.1 必须同步的核心代码

| 路径 | 作用 |
| --- | --- |
| `app/Http/Kernel.php` | 将 CyberCloak 前台上下文中间件放在路由模型绑定之前。 |
| `config/database.php` | 注册 `catalog_public` MySQL 连接。 |
| `config/horizon.php` | 保持 Horizon 任务使用独立中间件组，不继承前台切库上下文。 |
| `beike/Shop/Providers/PluginServiceProvider.php` | 注册已启用插件的迁移、路由、命令、模板和 Middleware。 |
| `beike/Libraries/Url.php` | 按当前 `StoreContext` 生成商品和分类稳定 URL。 |
| `beike/Models/Concerns/UsesCatalogConnection.php` | 商品、分类、品牌模型按当前模式选择 `mysql` 或 `catalog_public`。 |
| `beike/Admin/View/Components/Form/Select.php` | 支持 CyberCloak 后台的多选配置控件。 |

商品、分类和品牌模型：

```text
beike/Models/Brand.php
beike/Models/Category.php
beike/Models/CategoryDescription.php
beike/Models/CategoryPath.php
beike/Models/Product.php
beike/Models/ProductCategory.php
beike/Models/ProductDescription.php
beike/Models/ProductSku.php
beike/Models/ProductView.php
```

作用：展示模式读取 `catalog_public` 中的商品、SKU、分类、品牌、描述和分类路径；真实模式继续读取主库。

商品、分类和品牌仓储：

```text
beike/Repositories/BrandRepo.php
beike/Repositories/CategoryRepo.php
beike/Repositories/FlattenCategoryRepo.php
beike/Repositories/ProductRepo.php
```

作用：支持展示商品列表、搜索、分类树、品牌、首页商品模块和可售展示 SKU 查询。

首页和商品浏览入口：

```text
beike/Services/DesignService.php

beike/Shop/Http/Controllers/BrandController.php
beike/Shop/Http/Controllers/CategoryController.php
beike/Shop/Http/Controllers/HomeController.php
beike/Shop/Http/Controllers/ProductController.php
```

作用：切换首页商品模块、分类导航、品牌、商品详情、装修链接和 Banner 图片。

### 3.2 CyberCloak 插件目录

同步当前完整目录：

```text
plugins/CyberCloak/
```

| 路径 | 作用 |
| --- | --- |
| `Bootstrap.php` | 注册服务、Hook、商品分类路由绑定和后台扩展视图。 |
| `config.json`、`columns.php`、`Config/cyber_cloak.php` | 插件标识、后台设置字段和运行时配置。 |
| `Middleware/StoreContextMiddleware.php` | 解析请求访问凭据并在请求结束时清理上下文。 |
| `Middleware/API/ResolveStoreContext.php` | 为前台 API 设置商品库模式。 |
| `Middleware/Shop/ResolveStoreContext.php` | 为前台 HTML 设置商品库模式。 |
| `Controllers/AdminCyberCloakController.php`、`Routes/admin.php` | 提供访问 key、映射、Banner 和缓存的后台接口。 |
| `Views/admin/config_form.blade.php`、`Views/admin/mapping.blade.php` | 提供设置页和商品/分类/首页映射面板。 |
| `Console/ConfirmSkuMapping.php` | 人工确认 SKU 映射。 |
| `Console/ImportCatalog.php` | 从 JSON 导入展示商品库。 |
| `Console/RebuildSkuMappings.php` | 重建真实 SKU 到展示 SKU 的映射版本。 |
| `Console/RebuildRouteMappings.php` | 重建商品、分类稳定 URL 映射。 |

商品、分类和首页映射服务：

```text
plugins/CyberCloak/Services/AccessKeyService.php
plugins/CyberCloak/Services/ContextTicketService.php
plugins/CyberCloak/Services/StoreContext.php
plugins/CyberCloak/Services/CatalogResolver.php
plugins/CyberCloak/Services/CatalogImportService.php
plugins/CyberCloak/Services/CatalogRouteService.php
plugins/CyberCloak/Services/CatalogCategoryMappingResolver.php
plugins/CyberCloak/Services/CatalogNavigationService.php
plugins/CyberCloak/Services/SkuMappingService.php
plugins/CyberCloak/Services/CatalogContentMappingService.php
plugins/CyberCloak/Services/HomeDesignMappingService.php
plugins/CyberCloak/Services/HomeBannerImageMappingService.php
```

作用：签发和验证访问 key/Cookie、选择商品库、导入展示数据、建立 SKU 和分类映射、重建稳定 URL，并把首页装修的商品、分类链接和 Banner 图片转换为展示内容。

流量和 IP 服务：

```text
plugins/CyberCloak/Services/IpAccessService.php
plugins/CyberCloak/Services/MaxMindIpIntelligence.php
plugins/CyberCloak/Services/CloudIpRangeIntelligence.php
plugins/CyberCloak/Services/TrafficFunnelService.php
plugins/CyberCloak/Services/TrafficBehaviorService.php
plugins/CyberCloak/Services/TrafficRiskAuditService.php
```

作用：处理 IP、国家、语言、UA、ASN、云厂商和请求行为风险。这些能力与订单和购物车数据流程独立；当前 `Bootstrap.php` 已注册这些服务，因此部署完整当前插件版本时一并同步。

### 3.3 数据库迁移文件

商品、分类和首页映射需要以下迁移：

| 路径 | 写入范围和作用 |
| --- | --- |
| `plugins/CyberCloak/Migrations/2026_07_30_000001_create_catalog_tables.php` | 在 `catalog_public` 创建品牌、分类、商品、SKU 等展示表；在主库创建映射版本和商品/SKU 映射表。 |
| `plugins/CyberCloak/Migrations/2026_07_30_000002_add_catalog_route_maps.php` | 在主库创建稳定商品、分类 URL 映射表，并兼容展示表字段。 |
| `plugins/CyberCloak/Migrations/2026_07_31_000006_add_catalog_category_paths.php` | 在 `catalog_public` 创建分类路径表。 |
| `plugins/CyberCloak/Migrations/2026_07_31_000007_expand_catalog_product_schema.php` | 补齐展示商品库的商品字段。 |
| `plugins/CyberCloak/Migrations/2026_08_04_000009_create_home_banner_image_mappings.php` | 在主库创建首页 Banner 图片映射表。 |

完整插件还包括以下流量功能迁移：

```text
plugins/CyberCloak/Migrations/2026_07_30_000005_add_phase_six_management.php
plugins/CyberCloak/Migrations/2026_08_04_000008_add_traffic_risk_audit.php
```

`2026_08_04_000010_remove_order_cart_catalog_context.php` 是旧版订单、购物车、收藏字段的兼容清理迁移。全新测试库没有这些字段时只会完成字段检查；严格的目录裁剪发布包可以排除该文件。

## 4. 排除项

下列旧版订单/购物车隔离实现不进入全新测试环境发布包：

```text
plugins/CyberCloak/Services/CatalogCartItemService.php
plugins/CyberCloak/Services/CatalogOrderService.php
plugins/CyberCloak/Migrations/2026_07_30_000003_add_cart_catalog_context.php
plugins/CyberCloak/Migrations/2026_07_30_000004_add_order_catalog_context.php
```

订单、购物车、收藏、结算和库存继续使用测试环境原生 BeikeShop 文件：

```text
beike/Models/CartProduct.php
beike/Models/CustomerWishlist.php
beike/Models/Order.php
beike/Models/OrderProduct.php
beike/Repositories/CartRepo.php
beike/Repositories/CustomerRepo.php
beike/Repositories/OrderProductRepo.php
beike/Repositories/OrderRepo.php
beike/Services/StateMachineService.php
beike/Shop/Services/CartService.php
beike/Shop/Http/Controllers/CartController.php
beike/Shop/Http/Requests/CartRequest.php
```

`beike/Shop/Http/Resources/CartDetail.php` 只涉及购物车内商品链接是否使用展示 URL。保留生产版本时，购物车流程和链接均保持原生；同步 CyberCloak 版本时，仅商品详情链接会按当前模式转换，购物车数据和下单流程保持原生。

以下内容不属于运行时代码包：

```text
docs/
tests/
README*.md
CLAUDE.md
.env.example
plugins/Zelle/
plugins/CyberCloakSimple/
plugins/ProductPositionSort/
```

## 5. 环境配置和资源

在测试环境 `.env` 设置展示库连接：

```dotenv
CATALOG_PUBLIC_DB_HOST=mysql
CATALOG_PUBLIC_DB_PORT=3306
CATALOG_PUBLIC_DB_DATABASE=beike_public
CATALOG_PUBLIC_DB_USERNAME=APP_USER
CATALOG_PUBLIC_DB_PASSWORD=APP_PASSWORD

# 测试站为 HTTP 时设为 false；HTTPS 时设为 true。
CYBER_CLOAK_COOKIE_SECURE=false
```

首页装修继续使用主库 `base.design_setting`。以下文件属于数据或资源，按需同步：

```text
storage/app/catalog.json       展示商品导入 JSON
public/image/catalog/          Cloak Banner 目标图片
```

Docker 使用 `${APP_CODE_PATH:-.}:/app` 挂载代码时，普通 PHP/Blade 代码同步后只需清理缓存；Dockerfile、PHP 扩展或 Composer 依赖变化时再重建镜像。`/app` 只是容器内工作目录，宿主机项目目录由 `APP_CODE_PATH` 决定。

手工 LNMP 新部署默认使用执行安装脚本时的当前目录；需要指定目录时使用 `WEB_ROOT=/data/beikeshop ./install.sh`，不依赖 `/var/www`。

## 6. 部署和初始化顺序

1. 备份测试环境主库，并创建空的 `catalog_public` 数据库。
2. 同步第 3 节列出的核心代码和当前 `plugins/CyberCloak/` 目录。
3. 配置 `.env` 的 `CATALOG_PUBLIC_DB_*` 和 `CYBER_CLOAK_*`。
4. 确认 PHP-FPM 与 CLI 都加载 `pdo_mysql`，并确认主库与展示库连接可用。
5. 在后台插件管理中安装并启用 `cyberCloak`，插件编码为 `cyber_cloak`。安装入口会逐个执行插件目录中的迁移文件。
6. 清理缓存并确认插件命令已注册：

```bash
php artisan optimize:clear
php artisan list | grep cyber-cloak
```

7. 导入展示商品并建立映射：

```bash
php artisan cyber-cloak:import-catalog storage/app/catalog.json --dry-run
php artisan cyber-cloak:import-catalog storage/app/catalog.json --truncate
php artisan cyber-cloak:rebuild-sku-mappings --mode=exact
```

8. 在后台映射面板处理待确认 SKU，发布映射版本，执行“分类链接同步”“Banner 链接检查”“扫描 Banner 图片”和“清除缓存”。
9. 创建有效访问 key；无 key 请求验证展示商品，有效 key 请求验证真实商品。

## 7. 验收清单

- `php artisan plugin:list` 显示 `cyber_cloak` 已安装并启用。
- 主库和 `catalog_public` 均能连接。
- 无 key 首页、商品页、分类页显示展示数据。
- 有效 key 首次请求签发 Cookie，后续请求显示真实数据。
- 首页商品模块、分类导航、商品链接和 Banner 图片随模式变化。
- 购物车、收藏、订单创建、支付回调、库存和订单状态机仍使用主库原生流程。
- `catalog_public` 中不存在订单、购物车、客户、支付和售后业务表。
