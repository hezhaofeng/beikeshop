# 首页轮播图整块跳转

把首页轮播图的跳转配置移到插件里，图片和顺序仍由装修管理。

## 支持范围

- `slideshow`
- `img_text_slideshow`
- `img_text_slideshow_2`

## 使用方式

1. 在后台插件管理安装并启用“首页轮播图整块跳转”。
2. 打开插件配置页，按当前首页轮播项设置跳转类型：
   - `分类`
   - `自定义 URL`
3. 选择是否新窗口打开。
4. 保存后清理缓存：

```bash
php artisan optimize:clear
```

轮播图点击整块区域跳转，不再显示 `check details` 按钮。
