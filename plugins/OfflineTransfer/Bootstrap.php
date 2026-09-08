<?php

namespace Plugin\OfflineTransfer;

use Beike\Services\StateMachineService;
use Plugin\OfflineTransfer\Services\OfflineTransferPaymentService;

class Bootstrap
{
    /**
     * 注册线下转账在支付页、订单状态机和后台订单详情中的扩展点。
     */
    public function boot(): void
    {
        $this->registerQuantityPaymentRestriction();
        $this->registerPaymentDiscount();
        $this->registerPaymentPageData();
        $this->registerStateMachinePaymentRecord();
        $this->registerAdminViews();
    }

    /**
     * 将线下转账折扣加入结账总额，并同步支付方式名称提示。
     */
    private function registerPaymentDiscount(): void
    {
        add_hook_filter('service.total.maps', function (array $maps): array {
            $maps['offline_transfer_discount'] = \Plugin\OfflineTransfer\Services\OfflineTransferDiscountService::class;

            return $maps;
        }, 100);

        add_hook_filter('service.checkout.data', function (array $data): array {
            $setting = plugin_setting(OfflineTransferPaymentService::CODE, []);
            foreach ($data['payment_methods'] ?? [] as $index => $payment) {
                if (($payment['code'] ?? '') === OfflineTransferPaymentService::CODE) {
                    $data['payment_methods'][$index]['name'] = OfflineTransferPaymentService::paymentMethodName(
                        (string) ($payment['name'] ?? ''),
                        $setting
                    );
                }
            }
            if (($data['current']['payment_method_code'] ?? '') === OfflineTransferPaymentService::CODE) {
                $data['current']['payment_method_name'] = OfflineTransferPaymentService::paymentMethodName(
                    (string) ($data['current']['payment_method_name'] ?? ''),
                    $setting
                );
            }

            return $data;
        }, 20);
    }

    /**
     * 商品件数超过门槛时，仅展示线下转账并在服务端再次校验。
     */
    private function registerQuantityPaymentRestriction(): void
    {
        add_hook_filter('service.checkout.data', function (array $data): array {
            $setting  = plugin_setting(OfflineTransferPaymentService::CODE, []);
            $quantity = OfflineTransferPaymentService::checkoutQuantity($data);

            if (! OfflineTransferPaymentService::shouldForceOfflinePayment($setting, $quantity)) {
                return $data;
            }

            $offlinePayment = collect($data['payment_methods'] ?? [])
                ->filter(function ($payment): bool {
                    return data_get($payment, 'code') === OfflineTransferPaymentService::CODE;
                })
                ->values()
                ->all();

            // 线下插件未出现在可用支付列表时，不生成无效的支付方式。
            if (empty($offlinePayment)) {
                return $data;
            }

            $data['payment_methods']                = $offlinePayment;
            $data['current']['payment_method_code'] = OfflineTransferPaymentService::CODE;
            $data['current']['payment_method_name'] = $offlinePayment[0]['name'] ?? '';

            return $data;
        });

        add_hook_action('service.checkout.validate_confirm.after', function (array $data): void {
            $setting     = plugin_setting(OfflineTransferPaymentService::CODE, []);
            $quantity    = OfflineTransferPaymentService::checkoutQuantity($data);
            $paymentCode = (string) data_get($data, 'current.payment_method_code', '');

            if (OfflineTransferPaymentService::shouldForceOfflinePayment($setting, $quantity)
                && $paymentCode !== OfflineTransferPaymentService::CODE) {
                throw new \Exception(trans('OfflineTransfer::common.quantity_restriction_payment_only'));
            }
        });
    }

    /**
     * 向 Web 和移动端支付流程提供同一份转账说明。
     */
    private function registerPaymentPageData(): void
    {
        add_hook_filter('service.payment.pay.data', function (array $data): array {
            if (($data['order']->payment_method_code ?? '') !== OfflineTransferPaymentService::CODE) {
                return $data;
            }

            $service                              = new OfflineTransferPaymentService($data['order']);
            $data['offline_transfer_instruction'] = $service->instruction($data['payment_setting'] ?? []);

            return $data;
        });

        add_hook_filter('service.payment.mobile_pay.data', function (array $data): array {
            if (($data['order']->payment_method_code ?? '') !== OfflineTransferPaymentService::CODE) {
                return $data;
            }

            $service        = new OfflineTransferPaymentService($data['order']);
            $data['params'] = $service->mobilePaymentData($data['payment_setting'] ?? []);

            return $data;
        });
    }

    /**
     * 后台核验到账时，将支付审计记录与订单状态变更放入同一事务。
     */
    private function registerStateMachinePaymentRecord(): void
    {
        add_hook_filter('service.state_machine.machines', function (array $data): array {
            $order = $data['order'] ?? null;
            if (($order->payment_method_code ?? '') !== OfflineTransferPaymentService::CODE) {
                return $data;
            }

            $functions = $data['machines'][StateMachineService::UNPAID][StateMachineService::PAID] ?? [];
            if (! in_array('addPayment', $functions, true)) {
                $functions[] = 'addPayment';
            }
            $data['machines'][StateMachineService::UNPAID][StateMachineService::PAID] = $functions;

            return $data;
        });
    }

    /**
     * 注册插件设置页和订单详情中的人工到账核验区。
     */
    private function registerAdminViews(): void
    {
        add_hook_blade('admin.plugin.form', function ($callback, $output, $data) {
            if (($data['plugin']->code ?? '') !== OfflineTransferPaymentService::CODE) {
                return $output;
            }

            return view('OfflineTransfer::admin.config_form', $data)->render();
        }, 1);

        add_hook_filter('admin.order.show.data', function (array $data): array {
            $order = $data['order'] ?? null;
            if (($order->payment_method_code ?? '') !== OfflineTransferPaymentService::CODE) {
                return $data;
            }

            $data['html_items'][] = view('OfflineTransfer::admin.order_payment', [
                'order'   => $order,
                'payment' => $order->orderPayments->first(),
            ])->render();

            return $data;
        });
    }

}
