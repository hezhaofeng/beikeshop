#!/bin/bash

echo "===== 环境对比检查 ====="
echo ""

echo "【1. Laravel 版本】"
php artisan --version

echo ""
echo "【2. PHP 版本】"
php -v | head -1

echo ""
echo "【3. 检查 CSRF 中间件配置】"
echo "app/Http/Kernel.php 中的 web 中间件组："
grep -A 15 "protected \$middlewareGroups" app/Http/Kernel.php | grep -A 10 "'web'"

echo ""
echo "【4. 检查 session 配置】"
echo "SESSION_DRIVER=" $(grep SESSION_DRIVER .env || echo "未设置")
echo "SESSION_LIFETIME=" $(grep SESSION_LIFETIME .env || echo "未设置")

echo ""
echo "【5. 检查是否启用了 CSRF 保护】"
php -r "
require 'vendor/autoload.php';
\$app = require 'bootstrap/app.php';
\$kernel = \$app->make(Illuminate\Contracts\Http\Kernel::class);
\$reflection = new ReflectionObject(\$kernel);
\$property = \$reflection->getProperty('middlewareGroups');
\$property->setAccessible(true);
\$groups = \$property->getValue(\$kernel);
if (isset(\$groups['web'])) {
    echo '  web 中间件组包含的中间件：' . PHP_EOL;
    foreach (\$groups['web'] as \$middleware) {
        echo '    - ' . \$middleware . PHP_EOL;
    }
}
"

echo ""
echo "【6. 检查 VerifyCsrfToken 是否在排除列表中】"
if [ -f "app/Http/Middleware/VerifyCsrfToken.php" ]; then
    echo "VerifyCsrfToken.php 的 \$except 属性："
    grep -A 10 "protected \$except" app/Http/Middleware/VerifyCsrfToken.php
else
    echo "  ✗ VerifyCsrfToken.php 不存在"
fi

echo ""
echo "【7. 检查路由是否使用 web 中间件】"
echo "检查 products/autocomplete 路由："
php artisan route:list | grep "products/autocomplete" || echo "  未找到该路由"

echo ""
echo "===== 检查完成 ====="
