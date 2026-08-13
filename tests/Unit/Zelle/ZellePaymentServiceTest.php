<?php

namespace Tests\Unit\Zelle;

use Beike\Models\Order;
use Beike\Services\StateMachineService;
use Illuminate\Validation\ValidationException;
use Plugin\Zelle\Bootstrap;
use Plugin\Zelle\Services\ZellePaymentService;
use Tests\TestCase;

class ZellePaymentServiceTest extends TestCase
{
    /**
     * 支付页收款信息必须固定为订单的 USD 金额与订单号。
     */
    public function test_instruction_uses_usd_order_amount_and_recipient_settings(): void
    {
        $service = new ZellePaymentService($this->makeZelleOrder());

        $instruction = $service->instruction([
            'recipient_type'       => 'email',
            'recipient_identifier' => 'payments@example.test',
            'recipient_name'       => 'Example Store',
            'payment_note'         => '请填写订单号',
        ]);

        $this->assertSame('18.70', $instruction['amount']);
        $this->assertSame('USD', $instruction['currency']);
        $this->assertSame('ZELLE-1001', $instruction['order_number']);
        $this->assertSame('payments@example.test', $instruction['recipient_identifier']);
    }

    /**
     * 非 USD 订单进入 Zelle 流程时必须被服务端拒绝。
     */
    public function test_instruction_rejects_non_usd_order(): void
    {
        $order                = $this->makeZelleOrder();
        $order->currency_code = 'EUR';
        $service              = new ZellePaymentService($order);

        $this->expectException(ValidationException::class);
        $service->instruction([
            'recipient_identifier' => 'payments@example.test',
            'recipient_name'       => 'Example Store',
        ]);
    }

    /**
     * 后台核验必须将实际到账金额与订单金额逐分比对。
     */
    public function test_verified_payment_rejects_amount_mismatch(): void
    {
        $service = new ZellePaymentService($this->makeZelleOrder());

        $this->expectException(ValidationException::class);
        $service->verifiedPayment([
            'transaction_id'  => 'TX-001',
            'received_amount' => '18.69',
        ]);
    }

    /**
     * 买家声明只生成审计请求数据，不生成已支付状态。
     */
    public function test_customer_declaration_only_contains_audit_request(): void
    {
        $service = new ZellePaymentService($this->makeZelleOrder());

        $payment = $service->customerDeclaration([
            'transaction_id' => 'TX-001',
            'payer_name'     => 'Jane Doe',
        ]);

        $this->assertArrayHasKey('request', $payment);
        $this->assertArrayNotHasKey('response', $payment);
        $this->assertSame('customer_declaration', $payment['request']['source']);
        $this->assertSame('18.70', $payment['request']['expected_amount']);
    }

    /**
     * 状态机钩子只为 Zelle 的待支付到已支付转换追加支付审计。
     */
    public function test_bootstrap_adds_payment_record_to_zelle_paid_transition(): void
    {
        (new Bootstrap)->boot();

        $zelleOrder = $this->makeZelleOrder();
        $zelleData  = hook_filter('service.state_machine.machines', [
            'order'    => $zelleOrder,
            'machines' => StateMachineService::MACHINES,
        ]);

        $this->assertContains('addPayment', $zelleData['machines']['unpaid']['paid']);

        $otherOrder                      = $this->makeZelleOrder();
        $otherOrder->payment_method_code = 'paypal';
        $otherData                       = hook_filter('service.state_machine.machines', [
            'order'    => $otherOrder,
            'machines' => StateMachineService::MACHINES,
        ]);

        $this->assertNotContains('addPayment', $otherData['machines']['unpaid']['paid']);
    }

    /**
     * 三个 Blade 模板必须可以被 Laravel 编译器解析。
     */
    public function test_plugin_blade_templates_are_compilable(): void
    {
        foreach ([
            'plugins/Zelle/Views/admin/config_form.blade.php',
            'plugins/Zelle/Views/admin/order_payment.blade.php',
            'plugins/Zelle/Views/checkout/payment.blade.php',
        ] as $path) {
            $compiled = app('blade.compiler')->compileString(file_get_contents(base_path($path)));

            $this->assertNotSame('', $compiled);
        }
    }

    /**
     * 插件模板应能在未启用真实支付通道的单元环境中完成渲染。
     */
    public function test_plugin_blade_templates_are_renderable(): void
    {
        app('view')->addNamespace('Zelle', base_path('plugins/Zelle/Views'));
        // 单元环境未安装插件，模拟支付页生成链接所需的命名路由解析结果。
        app('url')->resolveMissingNamedRoutesUsing(static function (string $name, array $parameters, bool $absolute): ?string {
            if ($name !== 'shop.zelle.orders.declare') {
                return null;
            }

            $path = '/zelle/orders/' . rawurlencode((string) ($parameters['number'] ?? '')) . '/declaration';

            return $absolute ? url($path) : $path;
        });

        $plugin = new class
        {
            public string $code = 'zelle';

            /**
             * 提供配置页实际使用的最小插件设置接口。
             */
            public function getSetting(): array
            {
                return [
                    'recipient_type'       => 'email',
                    'recipient_identifier' => 'payments@example.test',
                    'recipient_name'       => 'Example Store',
                ];
            }
        };

        $service = new ZellePaymentService($this->makeZelleOrder());

        $configHtml = view('Zelle::admin.config_form', ['plugin' => $plugin])->render();
        $orderHtml  = view('Zelle::admin.order_payment', [
            'order'   => $this->makeZelleOrder(),
            'payment' => null,
        ])->render();
        $checkoutHtml = view('Zelle::checkout.payment', [
            'order'             => $this->makeZelleOrder(),
            'zelle_instruction' => $service->instruction($plugin->getSetting()),
        ])->render();

        $this->assertStringContainsString('Zelle', $configHtml);
        $this->assertStringContainsString('ZELLE-1001', $orderHtml);
        $this->assertStringContainsString('payments@example.test', $checkoutHtml);
    }

    /**
     * 创建无需数据库的待支付 Zelle 订单测试对象。
     */
    private function makeZelleOrder(): Order
    {
        return new Order([
            'number'              => 'ZELLE-1001',
            'total'               => '18.70',
            'currency_code'       => 'USD',
            'payment_method_code' => ZellePaymentService::CODE,
            'status'              => 'unpaid',
        ]);
    }
}
