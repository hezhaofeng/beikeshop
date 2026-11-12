# 热卖商品模块与批量选品

`Bestseller` 统一提供首页装修的热卖商品、最新商品模块，以及热卖商品、普通商品和选项卡商品模块的手动批量选品。插件标识继续使用 `bestseller`，后台只保留一个插件和一个选品入口。

## 使用方式

1. 将完整的 `Bestseller` 目录部署到 `plugins/`。
2. 在后台插件管理安装并启用“热卖商品模块与批量选品”；已有 `Bestseller` 安装可以直接升级，无需卸载重装。
3. 打开该插件的编辑页；首页装修中仍可使用热卖、最新商品两个模块。
4. 先选择首页模块；同类模块按 `module_id` 分开配置。选择“选项卡商品模块”后，还需选择具体 Tab。
5. 可直接输入商品名称后按回车或点击“查询”，无需选择分类；选择一级、二级或叶子分类后，名称会与分类组合筛选。候选商品按 `products.created_at DESC` 排列，一级、二级分类会包含全部子孙分类商品。
6. 勾选商品后可以继续切换分类，已选商品会保留在当前模块/Tab 的右侧列表。全部选择完成后点击“统一保存”。
7. 选择“手动：使用本页已选商品”后，对应模块按已选顺序显示；选择“自动”或手动列表为空时，该模块继续使用原有数据逻辑。
8. 选择热卖商品（`bestseller`）或商品（`product`）模块后，可在“首页数据来源”选择“全部时间销量：按历史有效订单销售件数排名”，然后点击“统一保存”。无需勾选商品，对应模块会自动按历史销售件数降序展示。热卖模块数量沿用装修设置；商品模块默认展示前 8 个，已有 `limit` 字段时沿用该值。

## 全部时间销量模式（v1.2.0）

- 汇总默认业务库 `order_products.quantity`，同一商品的不同 SKU、不同订单合并统计，不限制订单创建日期。
- 沿用后台销售排行的有效订单状态：`paid`、`shipped`、`completed`。不统计待支付、取消、退款中及已删除订单；部分退款若订单仍处于有效状态，仍按原订单商品数量统计。
- 销量相同按最近有效订单创建时间降序，再按商品 ID 降序，保证稳定排序。仅展示有有效销量、启用、未删除且有默认 SKU 的商品；不足配置数量时显示实际可用数量。
- 热卖模块的“自动”仍使用原 `products.sales` 累计字段，商品模块的“自动”仍使用装修中的商品列表；新模式直接统计当前有效订单，不回写该字段，也不修改任何订单或价格。
- v1.2.2 起，热卖（`bestseller`）和商品（`product`）模块均支持新模式，选项卡商品继续使用原自动/手动配置。各模块按 `module_id` 独立选择模式；切换模式不会清除原来的手动选品。旧版全局热卖设置不会自动应用到普通商品模块。
- CyberCloak 展示模式先按真实订单销量排名，再映射到可展示商品；未映射或不可展示的商品跳过并从后续名次补足，不在展示库查询订单。
- 排名实时读取，不建立新表或定时任务；历史订单量较大时应在目标环境确认查询耗时。

从 v1.1.0 升级时同步 `Services/AllTimeSalesService.php`、`Services/BestsellerSelectionService.php`、`Controllers/AdminBestsellerSelectorController.php`、`Views/admin/selector_panel.blade.php` 和 `config.json`，执行 `php artisan optimize:clear`。无需迁移数据库或构建前端；进入插件编辑页选择新模式并保存后生效，未切换的模块保持原行为。

从 v1.2.1 升级至 v1.2.2，同步 `Services/BestsellerSelectionService.php`、`Views/admin/selector_panel.blade.php` 和 `config.json`，清除缓存并强制刷新浏览器后，在商品模块选择新模式并保存即可，无需卸载重装。

### 编辑页看不到新模式时

- 编辑页首次进入时优先选择热卖商品模块；“全部时间销量”选项始终显示，v1.2.2 起选择热卖或商品模块时均可使用，选择选项卡商品模块时禁用并说明如何切换。刷新选品配置会保留当前有效的模块选择。
- 入口为“插件管理 → 热卖商品模块与批量选品 → 编辑”，在“首页商品模块”选择热卖商品或商品模块后查看“首页数据来源”。首页装修页中的标题等设置不提供该选品面板。
- 安装操作不会自动启用插件；如果整个批量选品面板都未出现，先确认插件已启用，再刷新编辑页。
- 后台版本应为 v1.2.2。请按上方对应的版本升级说明同步文件；如果服务器之前没有部署 v1.2.0，需同步从 v1.1.0 升级时列出的全部文件。
- 在实际应用目录或 Web 容器内执行 `php artisan optimize:clear`，然后强制刷新浏览器。如 PHP 开启 OPcache，还需刷新 OPcache 或重启对应 PHP 服务。重复安装不会替换服务器上未同步的源码。

## 数据与兼容性

- 配置存储在 `settings` 表的 `plugin/bestseller_selector` 空间：`modules[module_id]`，选项卡继续按 `tabs[index]` 区分；不新增商品字段或迁移。
- 合并后继续读写原配置空间，同时兼容旧版 `mode`、`product_ids`，已有商品、顺序、模块和选项卡配置无需重新选择。`bestseller_selector` 仅作为历史配置空间及接口名保留，不再是独立插件。
- 保留 `/后台前缀/bestseller-selector/{state,categories,products,save}` 接口、`bestseller_selector.*` 路由名及 `plugins_update` 权限，由 `Bestseller` 加载。
- 首页装修模块的 `code`、`module_id`、`Bestseller::shop/design_module_bestseller` 和 `Bestseller::shop/design_module_latest_products` 模板路径保持不变。
- 合并后由 `bestseller.status` 统一控制装修模块和手动选品；旧 `bestseller_selector.status` 不再控制功能。启用合并插件后会继续使用已保存的手动选品；停用合并插件也会停止普通商品和选项卡商品模块的手动覆盖。
- 保存时会移除已停用或没有默认 SKU 的商品。
- CyberCloak 展示模式会将保存的真实商品 ID 转换为已发布的展示商品映射；没有有效映射的商品不会显示。
- 单次最多保存 500 个商品。

## 从双插件版本升级

1. 同步完整的 `plugins/Bestseller/`，尤其是新增的 `Controllers/`、`Routes/`、`Services/` 和 `Views/admin/selector_panel.blade.php`，以及更新的 `Bootstrap.php`、`config.json`。
2. 移除旧 `plugins/BestsellerSelector/` 目录；使用 Git 部署时应同时应用该目录的删除记录。仅上传新插件、不移除旧插件会留下重复注册和旧编辑入口。
3. 保留 `settings` 表中 `space=bestseller_selector` 的全部配置，不要清理这部分数据，也不要卸载重装 `Bestseller`。旧 `plugins` 表安装记录无需为此修改，插件列表以磁盘上的 `config.json` 发现结果为准。
4. 在目标应用环境执行以下命令，清除指向旧控制器或模板的缓存；Docker 环境需在实际 Web 容器内运行。

```bash
php artisan optimize:clear
```

如生产环境开启 OPcache，请按实际 PHP-FPM/Nginx 部署方式刷新 OPcache 或重启对应服务。

5. 确认后台只有“热卖商品模块与批量选品”一个入口，且 `Bestseller` 已启用。检查旧选品是否完整、名称和分类查询、统一保存及首页展示；自动模式应保持原销量逻辑，最新商品装修模块应保持可用。

本次合并不修改数据库结构、不新增迁移、不修改主题或前端构建产物，也不需要安装新的 Composer/NPM 依赖。核心仍需包含 `service.design.module.content.after` 钩子及 `module_id` 传递逻辑；该依赖与合并前一致。CyberCloak 展示商品映射沿用现有服务。
