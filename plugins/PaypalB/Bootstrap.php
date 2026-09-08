<?php

namespace Plugin\PaypalB;

use Beike\Models\Plugin;
use Beike\Services\StateMachineService;
use Illuminate\Console\Application as ConsoleApplication;
use Plugin\PaypalB\Console\BackfillPaypalBShopOrders;
use Plugin\PaypalB\Console\DispatchPaypalBCallbacks;
use Plugin\PaypalB\Services\PaypalBCallbackService;
use Plugin\PaypalB\Services\PaypalBConfiguration;
use Plugin\PaypalB\Services\PaypalBLocalPaymentService;
use Plugin\PaypalB\Services\PaypalBTransactionService;

class Bootstrap
{
    /**
     * B 站仅提供网关和后台管理，不把自身注册为 B 站商城的支付方式。
     */
    public function boot(): void
    {
        app()->bind(PaypalBTransactionService::class, static fn (): PaypalBTransactionService => new PaypalBTransactionService(PaypalBConfiguration::all()));
        app()->bind(PaypalBCallbackService::class, static fn (): PaypalBCallbackService => new PaypalBCallbackService);

        // Kernel 只加载 app 和 beike 下的命令目录，插件命令必须在此显式注册，
        // 否则 Kernel::schedule() 里调度的 paypal-b:dispatch-callbacks 会因命令不存在而无法执行。
        // 注意用 Console\Application 而非 Artisan facade：后者代理到 Kernel，没有 starting()。
        if (app()->runningInConsole()) {
            ConsoleApplication::starting(static function (ConsoleApplication $artisan): void {
                $artisan->resolveCommands([
                    DispatchPaypalBCallbacks::class,
                    BackfillPaypalBShopOrders::class,
                ]);
            });
        }

        add_hook_blade('admin.plugin.form', function ($callback, $output, $data) {
            if (($data['plugin']->code ?? '') !== 'paypal_b') {
                return $output;
            }

            return view('PaypalB::admin.config_form', $data)->render();
        }, 1);

        $this->registerLocalPaymentMethod();
    }

    /**
     * B 站既是跨站收款网关，也要能收自己站内订单的款：
     * PayPal 的商户审核会在 B 站真实下单，走不到 PayPal 支付会被判定为空壳站点。
     *
     * plugins 表里本插件的 type 是 service，不会进 getPaymentMethods()，
     * 因此通过过滤器把自己补进支付方式列表，而不是改动已安装记录的 type。
     */
    private function registerLocalPaymentMethod(): void
    {
        add_hook_filter('repo.plugin.payment_methods', function ($methods) {
            try {
                $bPlugin = plugin(PaypalBLocalPaymentService::CODE);
                if (! $bPlugin || ! $bPlugin->getEnabled()) {
                    return $methods;
                }

                $record = Plugin::query()->where('code', PaypalBLocalPaymentService::CODE)->first();
                if (! $record || $methods->contains(fn ($item): bool => ($item->code ?? '') === PaypalBLocalPaymentService::CODE)) {
                    return $methods;
                }

                // PaymentMethodItem 只读取 type、code 和 plugin 三个属性。
                $record->plugin = $bPlugin;

                return $methods->push($record);
            } catch (\Throwable $exception) {
                // 支付方式列表不可用会导致整个结账页打不开，这里必须静默降级。
                report($exception);

                return $methods;
            }
        });

        add_hook_filter('service.payment.pay.data', function (array $data): array {
            if (($data['order']->payment_method_code ?? '') !== PaypalBLocalPaymentService::CODE) {
                return $data;
            }

            $data['paypal_b_instruction'] = (new PaypalBLocalPaymentService($data['order']))
                ->instruction($data['payment_setting'] ?? []);

            return $data;
        });

        add_hook_filter('service.payment.mobile_pay.data', function (array $data): array {
            if (($data['order']->payment_method_code ?? '') !== PaypalBLocalPaymentService::CODE) {
                return $data;
            }

            $data['params'] = (new PaypalBLocalPaymentService($data['order']))
                ->mobilePaymentData($data['payment_setting'] ?? []);

            return $data;
        });

        // 没有这个动作，setPayment() 传入的收款审计数据不会被写入 order_payments。
        add_hook_filter('service.state_machine.machines', function (array $data): array {
            if (($data['order']->payment_method_code ?? '') !== PaypalBLocalPaymentService::CODE) {
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
}
