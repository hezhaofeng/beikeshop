<?php

namespace Tests\Unit\OfflineTransfer;

use Beike\Models\Order;
use Beike\Services\StateMachineService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Plugin\OfflineTransfer\Bootstrap;
use Plugin\OfflineTransfer\Services\OfflineTransferDiscountService;
use Plugin\OfflineTransfer\Services\OfflineTransferPaymentService;
use Tests\TestCase;

class OfflineTransferPaymentServiceTest extends TestCase
{
    /**
     * 支付页必须展示后台保存的转账说明和订单自身的金额、币种、订单号。
     */
    public function test_instruction_uses_transfer_settings_and_order_data(): void
    {
        $service = new OfflineTransferPaymentService($this->makeOfflineTransferOrder());

        $instruction = $service->instruction([
            'transfer_instruction' => "收款人：测试商户\n账号：123456",
            'receipt_required'     => '1',
        ]);

        $this->assertSame("收款人：测试商户\n账号：123456", $instruction['transfer_instruction']);
        $this->assertTrue($instruction['receipt_required']);
        $this->assertSame('18.70', $instruction['amount']);
        $this->assertSame('EUR', $instruction['currency']);
        $this->assertSame('OFFLINE-1001', $instruction['order_number']);
    }

    /**
     * 未配置转账说明时不允许进入支付页，避免买家在无收款信息时付款。
     */
    public function test_instruction_requires_transfer_instruction(): void
    {
        $service = new OfflineTransferPaymentService($this->makeOfflineTransferOrder());

        $this->expectException(ValidationException::class);
        $service->instruction([]);
    }

    /**
     * 插件设置存储的字符串值 "0" 必须正确关闭凭证必填。
     */
    public function test_receipt_requirement_parses_database_string_zero(): void
    {
        $service = new OfflineTransferPaymentService($this->makeOfflineTransferOrder());

        $instruction = $service->instruction([
            'transfer_instruction' => '收款账户：123456',
            'receipt_required'     => '0',
        ]);

        $this->assertFalse($instruction['receipt_required']);
    }

    /**
     * 件数限制只在启用且严格超过门槛时生效。
     */
    public function test_quantity_restriction_uses_strictly_greater_threshold(): void
    {
        $setting = [
            'quantity_restriction_enabled'   => '1',
            'quantity_restriction_threshold' => '5',
        ];

        $this->assertFalse(OfflineTransferPaymentService::shouldForceOfflinePayment($setting, 5));
        $this->assertTrue(OfflineTransferPaymentService::shouldForceOfflinePayment($setting, 6));
        $this->assertFalse(OfflineTransferPaymentService::shouldForceOfflinePayment([
            'quantity_restriction_enabled'   => '0',
            'quantity_restriction_threshold' => '5',
        ], 6));
    }

    /**
     * 结账数据优先读取已选件数，并兼容商品列表格式。
     */
    public function test_checkout_quantity_reads_selected_quantity(): void
    {
        $this->assertSame(6, OfflineTransferPaymentService::checkoutQuantity([
            'carts' => ['quantity' => 6, 'carts' => [['quantity' => 2]]],
        ]));
        $this->assertSame(5, OfflineTransferPaymentService::checkoutQuantity([
            'carts' => [['quantity' => 2], ['quantity' => 3]],
        ]));
    }

    /**
     * 有效凭证路径只能位于本插件的私有凭证目录。
     */
    public function test_receipt_path_is_limited_to_private_plugin_directory(): void
    {
        $controller = new \Plugin\OfflineTransfer\Controllers\OfflineTransferController;
        $method     = new \ReflectionMethod($controller, 'isReceiptPath');

        $this->assertTrue($method->invoke($controller, 'offline-transfer-receipts/1/123e4567-e89b-12d3-a456-426614174000.pdf'));
        $this->assertFalse($method->invoke($controller, '../.env'));
        $this->assertFalse($method->invoke($controller, 'offline-transfer-receipts/1/receipt.exe'));
    }

    /**
     * 买家上传凭证仅生成待审核审计数据，不生成已支付状态。
     */
    public function test_customer_declaration_only_contains_audit_request_and_receipt(): void
    {
        $service = new OfflineTransferPaymentService($this->makeOfflineTransferOrder());

        $payment = $service->customerDeclaration([
            'transaction_id' => 'TX-001',
            'payer_name'     => 'Jane Doe',
        ], 'offline-transfer-receipts/1/receipt.pdf');

        $this->assertArrayHasKey('request', $payment);
        $this->assertArrayHasKey('receipt', $payment);
        $this->assertArrayNotHasKey('response', $payment);
        $this->assertSame('customer_declaration', $payment['request']['source']);
        $this->assertSame('offline-transfer-receipts/1/receipt.pdf', $payment['receipt']);
    }

    /**
     * 后台核验必须将实际到账金额与订单金额逐分比对。
     */
    public function test_verified_payment_rejects_amount_mismatch(): void
    {
        $service = new OfflineTransferPaymentService($this->makeOfflineTransferOrder());

        $this->expectException(ValidationException::class);
        $service->verifiedPayment([
            'transaction_id'  => 'TX-001',
            'received_amount' => '18.69',
        ]);
    }

    /**
     * 后台审核时，订单历史备注必须使用订单语言，不能受管理员当前语言影响。
     */
    public function test_payment_verified_comment_uses_order_locale(): void
    {
        app()->setLocale('zh_cn');
        $order         = $this->makeOfflineTransferOrder();
        $order->locale = 'en';
        $controller    = new \Plugin\OfflineTransfer\Controllers\OfflineTransferController;
        $method        = new \ReflectionMethod($controller, 'paymentVerifiedComment');

        $comment = $method->invoke($controller, $order);

        $this->assertSame('Offline transfer payment verified.', $comment);
        $this->assertSame('zh_cn', app()->getLocale());
    }

    /**
     * 订单查询只生成现有公共详情地址，订单号和邮箱缺一不可。
     */
    public function test_order_lookup_redirects_to_public_order_detail(): void
    {
        app('url')->resolveMissingNamedRoutesUsing(static function (string $name, array $parameters, bool $absolute): ?string {
            if ($name !== 'shop.orders.show') {
                return null;
            }

            $path = '/orders/' . rawurlencode((string) $parameters['number'])
                . '?email=' . rawurlencode((string) $parameters['email']);

            return $absolute ? url($path) : $path;
        });

        $request    = Request::create('/order-lookup', 'POST', ['number' => '2026081301001', 'email' => 'buyer@example.com']);
        $controller = new \Plugin\OfflineTransfer\Controllers\OfflineTransferController;
        $response   = $controller->findOrder($request);

        $this->assertStringContainsString('/orders/2026081301001?email=buyer%40example.com', $response->getTargetUrl());
    }

    /**
     * 状态机钩子只为线下转账的待支付到已支付转换追加支付审计。
     */
    public function test_bootstrap_adds_payment_record_only_to_offline_transfer_transition(): void
    {
        (new Bootstrap)->boot();

        $offlineTransferOrder = $this->makeOfflineTransferOrder();
        $offlineTransferData  = hook_filter('service.state_machine.machines', [
            'order'    => $offlineTransferOrder,
            'machines' => StateMachineService::MACHINES,
        ]);

        $this->assertContains('addPayment', $offlineTransferData['machines']['unpaid']['paid']);

        $otherOrder                      = $this->makeOfflineTransferOrder();
        $otherOrder->payment_method_code = 'paypal';
        $otherData                       = hook_filter('service.state_machine.machines', [
            'order'    => $otherOrder,
            'machines' => StateMachineService::MACHINES,
        ]);

        $this->assertNotContains('addPayment', $otherData['machines']['unpaid']['paid']);
    }

    /**
     * 件数超过门槛时，结账支付列表只保留线下转账。
     */
    public function test_bootstrap_filters_payment_methods_when_quantity_exceeds_threshold(): void
    {
        config(['bk.plugin.offline_transfer' => [
            'quantity_restriction_enabled'   => '1',
            'quantity_restriction_threshold' => '5',
        ]]);
        (new Bootstrap)->boot();

        $data = hook_filter('service.checkout.data', [
            'carts'           => ['quantity' => 6],
            'payment_methods' => [
                ['code' => 'paypal', 'name' => 'PayPal'],
                ['code' => OfflineTransferPaymentService::CODE, 'name' => '线下转账'],
            ],
            'current' => [
                'payment_method_code' => 'paypal',
                'payment_method_name' => 'PayPal',
            ],
        ]);

        $this->assertSame([OfflineTransferPaymentService::CODE], array_column($data['payment_methods'], 'code'));
        $this->assertSame(OfflineTransferPaymentService::CODE, $data['current']['payment_method_code']);
        $this->assertSame('线下转账', $data['current']['payment_method_name']);
    }

    public function test_discount_percentage_is_normalized_and_payment_name_is_linked(): void
    {
        $this->assertSame(15.5, OfflineTransferPaymentService::discountPercentage([
            OfflineTransferPaymentService::DISCOUNT_PERCENTAGE => '15.5',
        ]));
        $this->assertSame(100.0, OfflineTransferPaymentService::discountPercentage([
            OfflineTransferPaymentService::DISCOUNT_PERCENTAGE => '150',
        ]));
        $this->assertSame('Offline Transfer (15.5% discount)', OfflineTransferPaymentService::paymentMethodName(
            'Offline Transfer',
            [OfflineTransferPaymentService::DISCOUNT_PERCENTAGE => '15.5']
        ));
        $this->assertSame('Offline Transfer', OfflineTransferPaymentService::paymentMethodName('Offline Transfer', []));
    }

    public function test_discount_base_contains_product_and_insurance_but_excludes_shipping(): void
    {
        $this->assertSame(110.0, OfflineTransferDiscountService::discountBase([
            ['code' => 'sub_total', 'amount' => 100],
            ['code' => 'shipping', 'amount' => 20],
            ['code' => 'insurance_fee', 'amount' => 10],
        ]));
    }

    /**
     * 服务端确认钩子不能接受超过门槛时提交的其他支付方式。
     */
    public function test_bootstrap_rejects_other_payment_method_on_confirm(): void
    {
        config(['bk.plugin.offline_transfer' => [
            'quantity_restriction_enabled'   => '1',
            'quantity_restriction_threshold' => '5',
        ]]);
        (new Bootstrap)->boot();

        $this->expectException(\Exception::class);
        hook_action('service.checkout.validate_confirm.after', [
            'carts'   => ['quantity' => 6],
            'current' => ['payment_method_code' => 'paypal'],
        ]);
    }

    /**
     * 插件 Blade 模板必须可以被 Laravel 编译器解析。
     */
    public function test_plugin_blade_templates_are_compilable(): void
    {
        foreach ([
            'plugins/OfflineTransfer/Views/admin/config_form.blade.php',
            'plugins/OfflineTransfer/Views/admin/order_payment.blade.php',
            'plugins/OfflineTransfer/Views/checkout/payment.blade.php',
            'plugins/OfflineTransfer/Views/shop/order_lookup.blade.php',
            'plugins/OfflineTransfer/Views/shop/order_lookup_entry.blade.php',
            'plugins/OfflineTransfer/Views/shop/order_lookup_mobile_entry.blade.php',
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
        app('view')->addNamespace('OfflineTransfer', base_path('plugins/OfflineTransfer/Views'));
        // 单元环境未安装插件，模拟模板生成链接所需的命名路由解析结果。
        app('url')->resolveMissingNamedRoutesUsing(static function (string $name, array $parameters, bool $absolute): ?string {
            $paths = [
                'shop.offline-transfer.orders.declare'   => '/offline-transfer/orders/' . rawurlencode((string) ($parameters['number'] ?? '')) . '/declaration',
                'shop.offline-transfer.orders.lookup'    => '/order-lookup',
                'shop.offline-transfer.orders.find'      => '/order-lookup',
                'shop.orders.show'                       => '/orders/' . rawurlencode((string) ($parameters['number'] ?? '')) . '?email=' . rawurlencode((string) ($parameters['email'] ?? '')),
                'admin.offline-transfer.orders.receipt'  => '/admin/offline-transfer/orders/' . rawurlencode((string) ($parameters['id'] ?? '')) . '/receipt',
                'admin.offline-transfer.orders.confirm'  => '/admin/offline-transfer/orders/' . rawurlencode((string) ($parameters['id'] ?? '')) . '/confirm',
            ];
            if (! isset($paths[$name])) {
                return null;
            }

            return $absolute ? url($paths[$name]) : $paths[$name];
        });

        $plugin = new class
        {
            public string $code = 'offline_transfer';

            /**
             * 提供配置页实际使用的最小插件设置接口。
             */
            public function getSetting(): array
            {
                return [
                    'transfer_instruction'            => '收款账户：123456',
                    'receipt_required'                => '1',
                    'quantity_restriction_enabled'    => '1',
                    'quantity_restriction_threshold'  => '5',
                    'discount_percentage'             => '10',
                ];
            }
        };

        $order   = $this->makeOfflineTransferOrder();
        $service = new OfflineTransferPaymentService($order);

        $configHtml = view('OfflineTransfer::admin.config_form', ['plugin' => $plugin])->render();
        $orderHtml  = view('OfflineTransfer::admin.order_payment', [
            'order'   => $order,
            'payment' => null,
        ])->render();
        $checkoutHtml = view('OfflineTransfer::checkout.payment', [
            'order'                        => $order,
            'offline_transfer_instruction' => $service->instruction($plugin->getSetting()),
        ])->render();
        $lookupHtml            = view('OfflineTransfer::shop.order_lookup')->render();
        $lookupEntryHtml       = view('OfflineTransfer::shop.order_lookup_entry')->render();
        $lookupMobileEntryHtml = view('OfflineTransfer::shop.order_lookup_mobile_entry')->render();

        $this->assertStringContainsString('转账说明', $configHtml);
        $this->assertStringContainsString('线下支付件数门槛', $configHtml);
        $this->assertStringContainsString('线下转账折扣', $configHtml);
        $this->assertStringContainsString('OFFLINE-1001', $orderHtml);
        $this->assertStringContainsString('收款账户：123456', $checkoutHtml);
        $this->assertStringContainsString('查询订单详情', $lookupHtml);
        $this->assertStringContainsString('/order-lookup', $lookupEntryHtml);
        $this->assertStringContainsString('/order-lookup', $lookupMobileEntryHtml);
    }

    /**
     * 创建无需数据库的待支付线下转账订单测试对象。
     */
    private function makeOfflineTransferOrder(): Order
    {
        return new Order([
            'id'                  => 1,
            'number'              => 'OFFLINE-1001',
            'total'               => '18.70',
            'currency_code'       => 'EUR',
            'payment_method_code' => OfflineTransferPaymentService::CODE,
            'status'              => 'unpaid',
        ]);
    }
}
