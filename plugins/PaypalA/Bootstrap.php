<?php

namespace Plugin\PaypalA;

use Beike\Models\Order;
use Beike\Services\StateMachineService;
use Illuminate\Console\Application as ConsoleApplication;
use Plugin\PaypalA\Console\DispatchPaypalAFulfillment;
use Plugin\PaypalA\Services\PaypalAFulfillmentService;
use Plugin\PaypalA\Services\PaypalAPaymentService;

class Bootstrap
{
    /**
     * 注册支付页数据、订单支付审计和后台配置页。
     */
    public function boot(): void
    {
        // Kernel 只加载 app 和 beike 下的命令目录，插件命令必须在此显式注册，
        // 否则 Kernel::schedule() 里调度的 paypal-a:dispatch-fulfillment 会因命令不存在而无法执行。
        if (app()->runningInConsole()) {
            ConsoleApplication::starting(static function (ConsoleApplication $artisan): void {
                $artisan->resolveCommands([DispatchPaypalAFulfillment::class]);
            });
        }

        $this->registerPaymentPageData();
        $this->registerStateMachinePaymentRecord();
        $this->registerAdminSettings();
        $this->registerCheckoutValidation();
        $this->registerFulfillmentSync();
    }

    private function registerPaymentPageData(): void
    {
        add_hook_filter('service.payment.pay.data', function (array $data): array {
            if (($data['order']->payment_method_code ?? '') !== PaypalAPaymentService::CODE) {
                return $data;
            }

            $data['paypal_a_instruction'] = (new PaypalAPaymentService($data['order']))
                ->instruction($data['payment_setting'] ?? []);

            return $data;
        });

        add_hook_filter('service.payment.mobile_pay.data', function (array $data): array {
            if (($data['order']->payment_method_code ?? '') !== PaypalAPaymentService::CODE) {
                return $data;
            }

            $data['params'] = (new PaypalAPaymentService($data['order']))
                ->mobilePaymentData($data['payment_setting'] ?? []);

            return $data;
        });
    }

    private function registerStateMachinePaymentRecord(): void
    {
        add_hook_filter('service.state_machine.machines', function (array $data): array {
            if (($data['order']->payment_method_code ?? '') !== PaypalAPaymentService::CODE) {
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

    private function registerAdminSettings(): void
    {
        add_hook_blade('admin.plugin.form', function ($callback, $output, $data) {
            if (($data['plugin']->code ?? '') !== PaypalAPaymentService::CODE) {
                return $output;
            }

            return view('PaypalA::admin.config_form', $data)->render();
        }, 1);
    }

    /**
     * 前端隐藏支付方式并不能阻止直接提交，结账时再次验证双站配置。
     */
    private function registerCheckoutValidation(): void
    {
        add_hook_action('service.checkout.validate_confirm.after', function (array $data): void {
            if ((string) data_get($data, 'current.payment_method_code') !== PaypalAPaymentService::CODE) {
                return;
            }

            PaypalAPaymentService::bridgeConfiguration(plugin_setting(PaypalAPaymentService::CODE, []));
        });
    }

    /**
     * 发货单新增或修改后，把真实运单证据同步到 B。同步失败不会回滚 A 站发货操作，
     * 但会留下待重投记录，由队列退避重试和补偿命令兜底。
     */
    private function registerFulfillmentSync(): void
    {
        add_hook_filter('service.state_machine.change_status.after', function (array $data): array {
            $order = $data['order'] ?? null;
            if ($order instanceof Order) {
                $this->queueFulfillment($order);
            }

            return $data;
        });

        add_hook_action('admin.order.update_shipment.after', function (array $data): void {
            $orderId = (int) data_get($data, 'shipment.order_id', 0);
            if ($orderId > 0 && ($order = Order::query()->find($orderId))) {
                $this->queueFulfillment($order);
            }
        });

        add_hook_action('admin.order.add_shipment.after', function (): void {
            $order = request()->route('order');
            if ($order instanceof Order) {
                $this->queueFulfillment($order);
            }
        });
    }

    /**
     * 入队本身也必须静默失败：发货是后台的主操作，不能因为同步登记出错而中断。
     */
    private function queueFulfillment(Order $order): void
    {
        try {
            app(PaypalAFulfillmentService::class)->queue($order);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
