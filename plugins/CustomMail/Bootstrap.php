<?php

namespace Plugin\CustomMail;

use Beike\Models\Order;
use Beike\Models\Rma;
use Plugin\CustomMail\Services\CustomMailService;

/**
 * 自定义邮件插件入口。所有业务接入均使用现有 Hook 或模型事件，不修改核心文件。
 */
class Bootstrap
{
    public function boot(): void
    {
        $service = app(CustomMailService::class);

        add_hook_action('service.account.register.after', function ($customer) use ($service): void {
            $service->send('customer_registered', $customer);
        });

        // 订单创建和状态迁移都在核心事务中触发，服务会在提交后发送，避免回滚后误发。
        add_hook_filter('repository.order.create.after', function (array $data) use ($service): array {
            if ($data['order'] ?? null) {
                $service->sendAfterCommit('order_created', $data['order']);
            }

            return $data;
        }, 100);

        add_hook_filter('service.state_machine.change_status.after', function (array $data) use ($service): array {
            $order  = $data['order'] ?? null;
            $status = (string) ($data['status'] ?? '');
            if ($order && $status !== '') {
                $service->sendAfterCommit('order_status_' . $status, $order, [
                    'from_status' => (string) ($order->getOriginal('status') ?? ''),
                    'comment'     => (string) ($data['comment'] ?? ''),
                ]);
            }

            return $data;
        }, 100);

        // RMA 创建处没有专用 Hook，监听模型 created 事件即可保持核心代码不变。
        Rma::created(function (Rma $rma) use ($service): void {
            $service->sendAfterCommit('rma_created', $rma);
        });

        // 仅在下单事务成功提交后，按需为指定线下支付方式补发原生订单确认邮件。
        add_hook_action('service.checkout.confirm.after', function (array $data) use ($service): void {
            $order = $data['order'] ?? null;
            if ($order instanceof Order) {
                $service->sendNativeOrderConfirmationForTransferPaymentAfterCommit($order);
            }
        });

        add_hook_filter('admin.plugin.edit.data', function (array $data): array {
            $plugin = $data['plugin'] ?? null;
            if (! $plugin || ($plugin->code ?? '') !== CustomMailService::CODE) {
                return $data;
            }

            $data['paymentMethods'] = [
                ['code' => 'offline_transfer', 'label' => 'Offline Transfer', 'enabled' => true],
                ['code' => 'western_union', 'label' => 'Western Union', 'enabled' => true],
            ];

            return $data;
        });

        // 在订单详情页提供人工催款入口；自动状态邮件仍由上面的状态 Hook 负责。
        add_hook_filter('admin.order.show.data', function (array $data): array {
            $order = $data['order'] ?? null;
            if ($order instanceof Order) {
                $data['html_items'][] = view('CustomMail::admin.order_payment_reminder', [
                    'order' => $order,
                ])->render();
            }

            return $data;
        });
    }
}
