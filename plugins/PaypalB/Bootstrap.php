<?php

namespace Plugin\PaypalB;

use Beike\Models\Order;
use Beike\Models\Plugin;
use Beike\Services\StateMachineService;
use Illuminate\Console\Application as ConsoleApplication;
use Plugin\PaypalB\Console\BackfillPaypalBShopOrders;
use Plugin\PaypalB\Console\DispatchPaypalBCallbacks;
use Plugin\PaypalB\Console\PurgePaypalBData;
use Plugin\PaypalB\Console\SyncPaypalBPendingCaptures;
use Plugin\PaypalB\Models\PaypalBTransaction;
use Plugin\PaypalB\Services\PaypalBCallbackService;
use Plugin\PaypalB\Services\PaypalBConfiguration;
use Plugin\PaypalB\Services\PaypalBLocalPaymentService;
use Plugin\PaypalB\Services\PaypalBTransactionService;
use Plugin\PaypalB\Services\PaypalBWebhookRegistrar;

class Bootstrap
{
    /**
     * B 站同时提供跨站收款网关、后台管理，以及本站商城自己的 PayPal 支付方式。
     */
    public function boot(): void
    {
        app()->bind(PaypalBTransactionService::class, static fn (): PaypalBTransactionService => new PaypalBTransactionService(PaypalBConfiguration::all()));
        app()->bind(PaypalBCallbackService::class, static fn (): PaypalBCallbackService => new PaypalBCallbackService);
        // 只读原始设置：注册 Webhook 时插件其余配置可能还没填全。
        app()->bind(PaypalBWebhookRegistrar::class, static fn (): PaypalBWebhookRegistrar => PaypalBWebhookRegistrar::fromSetting(plugin_setting('paypal_b', [])));

        // Kernel 只加载 app 和 beike 下的命令目录，插件命令必须在此显式注册，
        // 否则 Kernel::schedule() 里调度的 paypal-b:dispatch-callbacks 会因命令不存在而无法执行。
        // 注意用 Console\Application 而非 Artisan facade：后者代理到 Kernel，没有 starting()。
        if (app()->runningInConsole()) {
            ConsoleApplication::starting(static function (ConsoleApplication $artisan): void {
                $artisan->resolveCommands([
                    DispatchPaypalBCallbacks::class,
                    BackfillPaypalBShopOrders::class,
                    PurgePaypalBData::class,
                    SyncPaypalBPendingCaptures::class,
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
        $this->registerPendingReviewNotice();
    }

    /**
     * PayPal 已受理但仍在审核的本站订单会落到结账成功页，而订单此时仍显示“待支付”。
     * 在标题下方说明原因，避免买家误以为付款失败而再付一次。
     */
    private function registerPendingReviewNotice(): void
    {
        add_hook_blade('checkout.success.checkout_success_title.after', function ($callback, $output, array $data = []): string {
            $viewData = is_array($data['data'] ?? null) ? $data['data'] : $data;
            $order    = $viewData['order'] ?? null;

            if (! $order instanceof Order
                || $order->payment_method_code !== PaypalBLocalPaymentService::CODE
                || $order->status !== StateMachineService::UNPAID
                || ! PaypalBTransaction::hasLocalPendingCaptureForOrder((int) $order->id)) {
                return (string) $output;
            }

            return (string) $output . view('PaypalB::checkout.partials.review_notice')->render();
        }, 10);
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

            try {
                $data['paypal_b_instruction'] = (new PaypalBLocalPaymentService($data['order']))
                    ->instruction($data['payment_setting'] ?? []);
            } catch (\Illuminate\Validation\ValidationException $exception) {
                report($exception);
                $data['paypal_b_error'] = collect($exception->errors())->flatten()->first()
                    ?: trans('PaypalB::common.local_payment_start_failed');
            } catch (\Throwable $exception) {
                report($exception);
                $data['paypal_b_error'] = trans('PaypalB::common.local_payment_start_failed');
            }

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

            return $this->adjustMirrorOrderMachines($data);
        });
    }

    /**
     * 跨站镜像订单走状态机时：不向买家发通知（A 站已发），默认不扣 B 站库存、不累计销量
     * （B 站目录只是展示与收款载体，实际发货在 A 站）。自营订单不受影响。
     */
    private function adjustMirrorOrderMachines(array $data): array
    {
        $order = $data['order'] ?? null;
        if (! $order instanceof Order || ! $order->id) {
            return $data;
        }
        if (! PaypalBTransaction::isMirrorOrder((int) $order->id)) {
            return $data;
        }

        $deductStock = false;
        try {
            $deductStock = (bool) PaypalBConfiguration::all()['mirror_order_deduct_stock'];
        } catch (\Throwable) {
            // 配置不完整时按最保守的“不扣库存”处理。
        }

        $removed = ['notifyNewOrder', 'notifyUpdateOrder', 'uniPushMessage', 'updateSales'];
        if (! $deductStock) {
            $removed[] = 'subStock';
            $removed[] = 'revertStock';
        }
        foreach ($data['machines'] as $from => $targets) {
            foreach ($targets as $to => $functions) {
                $data['machines'][$from][$to] = array_values(array_filter(
                    (array) $functions,
                    static fn ($function): bool => ! is_string($function) || ! in_array($function, $removed, true)
                ));
            }
        }

        return $data;
    }
}
