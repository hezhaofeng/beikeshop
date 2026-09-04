<?php

namespace Plugin\CustomMail;

use Beike\Models\Order;
use Beike\Models\Rma;
use Beike\Repositories\PluginRepo;
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

            $methods = [];

            try {
                foreach (PluginRepo::getPaymentMethods() as $payment) {
                    $paymentPlugin = $payment->plugin ?? null;
                    $code          = trim((string) ($payment->code ?? ''));
                    if ($code === '' || isset($methods[$code])) {
                        continue;
                    }

                    $methods[$code] = [
                        'code'    => $code,
                        'label'   => $paymentPlugin?->getLocaleName() ?: $code,
                        'enabled' => true,
                    ];
                }

                // 保留已启用但因币种等运行时条件暂未出现在支付列表中的支付插件。
                foreach (PluginRepo::allPlugins()->where('type', 'payment') as $payment) {
                    $paymentPlugin = plugin($payment->code);
                    $code          = trim((string) ($payment->code ?? ''));
                    if ($code === '' || isset($methods[$code]) || ! $paymentPlugin?->getEnabled()) {
                        continue;
                    }

                    $methods[$code] = [
                        'code'    => $code,
                        'label'   => $paymentPlugin->getLocaleName() ?: $code,
                        'enabled' => true,
                    ];
                }
            } catch (\Throwable $exception) {
                report($exception);
            }

            $savedCodes = [];
            foreach (CustomMailService::PAYMENT_METHOD_TEMPLATE_EVENTS as $event) {
                $saved = data_get($plugin->getSetting(), 'templates.' . $event . '.payment_methods', []);
                if (is_array($saved)) {
                    $savedCodes = array_merge($savedCodes, array_keys($saved));
                }
            }
            foreach (array_unique($savedCodes) as $code) {
                $code = trim((string) $code);
                if ($code !== '' && ! isset($methods[$code])) {
                    $methods[$code] = [
                        'code'    => $code,
                        'label'   => "已保存配置（{$code}）",
                        'enabled' => false,
                    ];
                }
            }

            $data['paymentMethods'] = array_values($methods);

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
