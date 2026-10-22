<?php

namespace Plugin\PaypalA\Middleware\Shop;

use Beike\Repositories\OrderRepo;
use Beike\Services\StateMachineService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Plugin\PaypalA\Controllers\PaypalAController;
use Plugin\PaypalA\Services\PaypalAPaymentService;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * 买家点击“提交订单”后不再停留在 A 站支付页：直接创建 B 站会话并 302 到 B 站支付页。
 *
 * 只在以下情况放行到 A 站支付页：本次请求带有错误提示（B 站不可用、买家取消后回跳等，需要展示原因）、
 * 或买家已付款正在等待 PayPal 确认。控制器 pay() 外层 try/catch 会吞掉
 * HttpResponseException，因此必须在中间件层返回重定向。
 *
 * GET 请求会被邮件链接扫描、浏览器预加载和买家刷新反复触发：最近仍可付款的会话直接复用，
 * 只有确实需要新会话时才创建，并与 POST 发起入口同样限流，避免把买家正在付款的会话作废。
 */
class RedirectPaypalAPayment
{
    /**
     * 与 paypal_a.orders.start 路由的 throttle:10,1 保持一致。
     */
    private const MAX_AUTO_STARTS_PER_MINUTE = 10;

    public function handle(Request $request, Closure $next)
    {
        if (! $request->isMethod('GET') || ! $request->routeIs('shop.orders.pay')) {
            return $next($request);
        }

        $redirect = $this->redirectForOrder($request);

        return $redirect ?? $next($request);
    }

    private function redirectForOrder(Request $request): ?RedirectResponse
    {
        // 回跳或上一次发起失败带回的提示必须让买家看到，此时不再自动跳转，避免死循环。
        $errors = $request->session()->get('errors');
        if ($errors && method_exists($errors, 'any') && $errors->any()) {
            return null;
        }

        $number = (string) $request->route('number');
        $order  = OrderRepo::getOrderByNumber($number, current_customer());
        if (! $order
            || (string) $order->payment_method_code !== PaypalAPaymentService::CODE
            || (string) $order->status !== StateMachineService::UNPAID) {
            return null;
        }

        try {
            $configuration = PaypalAPaymentService::bridgeConfiguration(plugin_setting(PaypalAPaymentService::CODE, []));
        } catch (\Throwable) {
            // 配置错误交给支付页按既有流程提示。
            return null;
        }
        if (PaypalAPaymentService::orderHasPendingCapture($order)) {
            return null;
        }

        try {
            // 与核心 Account/OrderController::pay 相同的买家授权；未授权时交给核心支付页按既有流程处理。
            $order = app(PaypalAController::class)->authorizedShopOrder($request, $number);
        } catch (\Throwable) {
            return null;
        }

        $method = $configuration['wallet_enabled'] ? 'wallet' : 'card';
        $reuse  = PaypalAPaymentService::recentCheckoutUrl($order, $configuration, $method);
        if ($reuse !== null) {
            if (! $this->payBeforeHookAllows($order)) {
                return null;
            }
            $redirect = redirect()->away($reuse);
            $redirect->headers->set('Referrer-Policy', 'no-referrer');

            return $redirect;
        }

        $limiterKey = 'paypal_a:auto_start:' . hash('sha256', $number . '|' . (string) $request->ip());
        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_AUTO_STARTS_PER_MINUTE)) {
            Log::warning('PaypalA 支付页自动发起过于频繁，本次改为渲染支付页。', ['order_number' => $number]);

            return null;
        }
        if (! $this->payBeforeHookAllows($order)) {
            return null;
        }
        RateLimiter::hit($limiterKey, 60);

        $request->merge(['method' => $method]);
        try {
            $response = app(PaypalAController::class)->start($request, $number);
        } catch (HttpExceptionInterface) {
            // 游客未授权等 HTTP 异常交给支付页控制器按既有流程处理。
            return null;
        }

        // start() 成功返回 B 站地址的 302；失败返回带错误提示的支付页 302，下一次请求因带错误而正常渲染。
        return $response instanceof RedirectResponse ? $response : null;
    }

    /**
     * 核心支付页在渲染前触发 account.order.pay.before，插件可借此拦截付款（抛出异常）。
     * 中间件直接跳转时核心控制器不会执行，因此只在即将跳转前触发一次；钩子拒绝时放行给核心控制器，
     * 由它再次触发钩子并按既有方式向买家展示原因。
     */
    private function payBeforeHookAllows($order): bool
    {
        try {
            hook_action('account.order.pay.before', ['order' => $order]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
