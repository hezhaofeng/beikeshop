<?php

namespace Plugin\Zelle;

use Beike\Services\StateMachineService;
use Plugin\Zelle\Services\ZellePaymentService;

class Bootstrap
{
    /**
     * 注册 Zelle 在前台、后台和订单状态机中的扩展点。
     */
    public function boot(): void
    {
        $this->registerPaymentMethodFilter();
        $this->registerCheckoutValidation();
        $this->registerPaymentPageData();
        $this->registerStateMachinePaymentRecord();
        $this->registerAdminViews();
    }

    /**
     * Zelle 仅接收 USD，结账时隐藏其他货币下的支付方式。
     */
    private function registerPaymentMethodFilter(): void
    {
        add_hook_filter('repo.plugin.payment_methods', function ($methods) {
            if (strtoupper(current_currency_code()) === ZellePaymentService::SUPPORTED_CURRENCY) {
                return $methods;
            }

            return $methods->reject(function ($method): bool {
                return $method->code === ZellePaymentService::CODE;
            })->values();
        });
    }

    /**
     * 服务端再次校验支付方式和订单货币，避免通过请求参数绕过前台展示条件。
     */
    private function registerCheckoutValidation(): void
    {
        add_hook_action('service.checkout.validate_confirm.after', function (array $checkoutData): void {
            $paymentMethodCode = (string) data_get($checkoutData, 'current.payment_method_code', '');
            if ($paymentMethodCode === ZellePaymentService::CODE) {
                ZellePaymentService::assertCurrencyCode(current_currency_code());
            }
        });
    }

    /**
     * 向 Web 和移动端支付流程提供同一份 Zelle 收款指引。
     */
    private function registerPaymentPageData(): void
    {
        add_hook_filter('service.payment.pay.data', function (array $data): array {
            if (($data['order']->payment_method_code ?? '') !== ZellePaymentService::CODE) {
                return $data;
            }

            $service                   = new ZellePaymentService($data['order']);
            $data['zelle_instruction'] = $service->instruction($data['payment_setting'] ?? []);

            return $data;
        });

        add_hook_filter('service.payment.mobile_pay.data', function (array $data): array {
            if (($data['order']->payment_method_code ?? '') !== ZellePaymentService::CODE) {
                return $data;
            }

            $service        = new ZellePaymentService($data['order']);
            $data['params'] = $service->mobilePaymentData($data['payment_setting'] ?? []);

            return $data;
        });
    }

    /**
     * Zelle 后台确认到账时，将支付审计记录与订单状态变更放入同一事务。
     */
    private function registerStateMachinePaymentRecord(): void
    {
        add_hook_filter('service.state_machine.machines', function (array $data): array {
            $order = $data['order'] ?? null;
            if (($order->payment_method_code ?? '') !== ZellePaymentService::CODE) {
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
            if (($data['plugin']->code ?? '') !== ZellePaymentService::CODE) {
                return $output;
            }

            return view('Zelle::admin.config_form', $data)->render();
        }, 1);

        add_hook_filter('admin.order.show.data', function (array $data): array {
            $order = $data['order'] ?? null;
            if (($order->payment_method_code ?? '') !== ZellePaymentService::CODE) {
                return $data;
            }

            $data['html_items'][] = view('Zelle::admin.order_payment', [
                'order'   => $order,
                'payment' => $order->orderPayments->first(),
            ])->render();

            return $data;
        });
    }
}
