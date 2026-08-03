# 前台商品位置排序

本插件通过 `repo.product.builder` Hook 恢复前台分类商品按 `products.position ASC` 的默认排序。

## 生效范围

- 仅前台 `shop.categories.show` 分类商品列表；
- 仅请求没有 `sort` 或 `order` 参数时生效；
- 后台、搜索页及用户主动选择的排序保持核心逻辑；
- `position` 相同时按 `products.id DESC` 保持结果稳定。

## 安装与启用

1. 将 `ProductPositionSort` 目录放入项目 `plugins/` 目录。
2. 在后台插件管理中找到“前台商品位置排序”，执行安装。
3. 安装完成后启用插件。
4. 清理应用缓存：`php artisan optimize:clear`。
5. 生产环境如启用了 PHP OPcache，重启 PHP-FPM 或清理 OPcache。

启用状态保存在 `settings` 表的插件设置中，单纯复制目录不会自动执行 `Bootstrap.php`。
