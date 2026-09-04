<?php

namespace Plugin\CustomMail\Controllers;

use Beike\Models\Order;
use Beike\Services\StateMachineService;
use Illuminate\Http\RedirectResponse;
use Plugin\CustomMail\Services\CustomMailService;

class CustomMailController
{
    /**
     * 从后台订单详情手动发送待支付催款邮件。
     */
    public function sendPaymentReminder(Order $order): RedirectResponse
    {
        $redirect = redirect(admin_route('orders.show', $order));

        if ($order->status !== StateMachineService::UNPAID) {
            return $redirect->withErrors('只有待支付订单可以发送催款邮件。');
        }

        $service = app(CustomMailService::class);
        if (! $service->enabled($service->settings(), 'order_payment_reminder')) {
            return $redirect->withErrors('请先在自定义邮件设置中启用“待支付催款（手动）”场景。');
        }

        if (! $service->send('order_payment_reminder', $order)) {
            return $redirect->withErrors('催款邮件未发送：请检查模板内容和收件人设置。');
        }

        return $redirect->with('success', '催款邮件已加入发送队列。');
    }
}
