<?php

namespace Tests\Unit\CustomMail;

use Illuminate\Support\HtmlString;
use PHPUnit\Framework\TestCase;
use Plugin\CustomMail\Services\CustomMailService;

class CustomMailServiceTest extends TestCase
{
    public function test_template_render_escapes_values_and_keeps_unknown_tokens(): void
    {
        $service = new CustomMailService;

        $result = $service->render('您好 {{customer_name}}，{{unknown}}', [
            'customer_name' => '<客户>',
        ]);

        $this->assertSame('您好 &lt;客户&gt;，{{unknown}}', $result);
    }

    public function test_customer_name_resolves_logged_in_and_guest_order_names(): void
    {
        $service = new CustomMailService;

        $this->assertSame(
            '登录用户',
            $service->customerName((object) [
                'customer_name'          => '登录用户',
                'shipping_customer_name' => '收货人姓名',
            ])
        );
        $this->assertSame(
            '访客全名',
            $service->customerName((object) [
                'customer_name'          => '',
                'shipping_customer_name' => ' 访客全名 ',
            ])
        );
        $this->assertSame(
            '支付人姓名',
            $service->customerName((object) [
                'customer_name'          => '',
                'shipping_customer_name' => '',
                'payment_customer_name'  => '支付人姓名',
            ])
        );
    }

    public function test_order_html_placeholders_are_not_escaped_but_text_values_are(): void
    {
        $service = new CustomMailService;

        $result = $service->render('{{order_products_html}} {{order_totals_html}} {{shipping_address_html}} {{view_order_button_html}} {{shipping_telephone}} {{customer_name}}', [
            'order_products_html'    => new HtmlString('<table><tr><td>商品</td></tr></table>'),
            'order_totals_html'      => new HtmlString('<table><tr><td>Order total</td><td>$10.00</td></tr></table>'),
            'shipping_address_html'  => new HtmlString('<strong>地址</strong>'),
            'view_order_button_html' => new HtmlString('<p><a href="https://shop.example/orders/1001">View Order</a></p>'),
            'shipping_telephone'     => '<script>alert(1)</script>',
            'customer_name'          => '<客户>',
        ]);

        $this->assertSame(
            '<table><tr><td>商品</td></tr></table> <table><tr><td>Order total</td><td>$10.00</td></tr></table> <strong>地址</strong> <p><a href="https://shop.example/orders/1001">View Order</a></p> &lt;script&gt;alert(1)&lt;/script&gt; &lt;客户&gt;',
            $result
        );
        $this->assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            $service->render('{{order_products_html}}', ['order_products_html' => '<script>alert(1)</script>'])
        );
        $this->assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            $service->render('{{order_totals_html}}', ['order_totals_html' => '<script>alert(1)</script>'])
        );
        $this->assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            $service->render('{{view_order_button_html}}', ['view_order_button_html' => '<script>alert(1)</script>'])
        );
    }

    public function test_event_must_be_enabled_and_plugin_status_must_be_on(): void
    {
        $service = new CustomMailService;

        $this->assertFalse($service->enabled(['status' => '0', 'enabled_events' => ['order_created']], 'order_created'));
        $this->assertFalse($service->enabled(['status' => 1, 'enabled_events' => []], 'order_created'));
        $this->assertTrue($service->enabled(['status' => 1, 'enabled_events' => ['order_created']], 'order_created'));
    }

    public function test_recipients_only_include_selected_customer_admin_and_global_additional_addresses(): void
    {
        $service = new CustomMailService;

        $recipients = $service->recipients(
            ['additional_recipients' => 'extra-one@example.com; extra-two@example.com, invalid, admin@example.com'],
            [
                'recipients'        => ['customer'],
                'custom_recipients' => 'legacy-template@example.com',
            ],
            (object) ['email' => 'customer@example.com'],
            'admin@example.com'
        );

        $this->assertSame([
            'admin@example.com',
            'customer@example.com',
            'extra-one@example.com',
            'extra-two@example.com',
        ], $recipients);
    }

    public function test_customer_is_not_sent_when_template_does_not_select_customer(): void
    {
        $service = new CustomMailService;

        $recipients = $service->recipients(
            ['additional_recipients' => 'extra@example.com'],
            ['recipients' => []],
            (object) ['email' => 'customer@example.com'],
            'admin@example.com'
        );

        $this->assertSame(['admin@example.com', 'extra@example.com'], $recipients);
    }

    public function test_pending_payment_event_and_payment_url_placeholder_are_supported(): void
    {
        $service = new CustomMailService;

        $this->assertArrayHasKey('order_status_unpaid', CustomMailService::EVENTS);
        $this->assertArrayHasKey('order_payment_reminder', CustomMailService::EVENTS);
        $this->assertSame(
            '<a href="https://shop.example/orders/202609010001/pay">立即支付</a>',
            $service->render('<a href="{{payment_url}}">立即支付</a>', [
                'payment_url' => 'https://shop.example/orders/202609010001/pay',
            ])
        );
    }

    public function test_payment_reminder_prefers_payment_method_template_and_falls_back_to_generic(): void
    {
        $service  = new CustomMailService;
        $settings = [
            'templates' => [
                'order_payment_reminder' => [
                    'subject'         => '通用主题',
                    'body'            => '通用正文',
                    'payment_methods' => [
                        'wise' => [
                            'subject' => 'Wise 主题',
                            'body'    => 'Wise 正文',
                        ],
                        'western_union' => [
                            'subject' => '',
                            'body'    => '',
                        ],
                    ],
                ],
            ],
        ];

        $wiseOrder         = (object) ['payment_method_code' => 'wise'];
        $stripeOrder       = (object) ['payment_method_code' => 'stripe'];
        $westernUnionOrder = (object) ['payment_method_code' => 'western_union'];

        $this->assertSame('Wise 主题', $service->paymentReminderTemplate($settings, $wiseOrder)['subject']);
        $this->assertSame('通用主题', $service->paymentReminderTemplate($settings, $stripeOrder)['subject']);
        $this->assertSame('通用主题', $service->paymentReminderTemplate($settings, $westernUnionOrder)['subject']);
    }

    public function test_unpaid_order_template_also_prefers_payment_method_template(): void
    {
        $service  = new CustomMailService;
        $settings = [
            'templates' => [
                'order_status_unpaid' => [
                    'subject'         => '通用待支付主题',
                    'body'            => '通用待支付正文',
                    'payment_methods' => [
                        'offline_transfer' => [
                            'subject' => '线下转账待支付主题',
                            'body'    => '线下转账待支付正文',
                        ],
                    ],
                ],
            ],
        ];

        $offlineOrder      = (object) ['payment_method_code' => 'offline_transfer'];
        $westernUnionOrder = (object) ['payment_method_code' => 'western_union'];

        $this->assertSame(
            '线下转账待支付主题',
            $service->paymentMethodTemplate($settings, 'order_status_unpaid', $offlineOrder)['subject']
        );
        $this->assertSame(
            '通用待支付主题',
            $service->paymentMethodTemplate($settings, 'order_status_unpaid', $westernUnionOrder)['subject']
        );
    }

    public function test_payment_info_html_uses_selected_profile_and_renders_order_placeholders(): void
    {
        $service  = new CustomMailService;
        $settings = [
            'payment_info' => [
                'offline_transfer' => [
                    'strategy'         => 'manual',
                    'selected_profile' => 'bank_2',
                    'profiles'         => [
                        ['id' => 'bank_1', 'name' => '账户 1', 'enabled' => '1', 'html' => '<p>账户一</p>'],
                        ['id' => 'bank_2', 'name' => '账户 2', 'enabled' => '1', 'html' => '<table><tr><td>{{order_number}}</td></tr></table>'],
                    ],
                ],
            ],
        ];

        $this->assertSame(
            '<table><tr><td>OT-1002</td></tr></table>',
            $service->paymentInfoHtml(
                (object) ['payment_method_code' => 'offline_transfer', 'number' => 'OT-1002'],
                $settings,
                ['order_number' => 'OT-1002']
            )
        );
    }

    public function test_payment_info_html_round_robin_is_stable_per_order_and_empty_for_other_methods(): void
    {
        $service  = new CustomMailService;
        $settings = [
            'payment_info' => [
                'western_union' => [
                    'strategy' => 'round_robin',
                    'profiles' => [
                        ['id' => 'wu_1', 'enabled' => true, 'html' => '<p>WU-1</p>'],
                        ['id' => 'wu_2', 'enabled' => true, 'html' => '<p>WU-2</p>'],
                    ],
                ],
            ],
        ];
        $order = (object) ['payment_method_code' => 'western_union', 'number' => 'OT-STABLE'];

        $this->assertSame($service->paymentInfoHtml($order, $settings), $service->paymentInfoHtml($order, $settings));
        $this->assertSame('', $service->paymentInfoHtml((object) ['payment_method_code' => 'paypal', 'number' => 'OT-OTHER'], $settings));
    }

    public function test_payment_info_rotation_batch_size_is_bounded_and_defaults_to_one(): void
    {
        $service = new CustomMailService;

        $this->assertSame(1, $service->rotationBatchSize([]));
        $this->assertSame(5, $service->rotationBatchSize(['rotation_batch_size' => '5']));
        $this->assertSame(1, $service->rotationBatchSize(['rotation_batch_size' => '0']));
        $this->assertSame(1_000_000, $service->rotationBatchSize(['rotation_batch_size' => '9999999']));
        $this->assertSame([0, 0, 0, 1, 1, 1, 2], array_map(
            fn (int $assignedCount): int => $service->rotationProfileIndex($assignedCount, 3, 3),
            range(0, 6)
        ));
    }

    public function test_payment_info_html_is_sanitized_before_insertion(): void
    {
        $service  = new CustomMailService;
        $settings = [
            'payment_info' => [
                'offline_transfer' => [
                    'strategy' => 'manual',
                    'profiles' => [
                        ['id' => 'bank_1', 'enabled' => true, 'html' => '<table onclick="alert(1)"><script>alert(1)</script><tr><td>账户</td></tr></table>'],
                    ],
                ],
            ],
        ];

        $this->assertSame(
            '<table><tr><td>账户</td></tr></table>',
            $service->paymentInfoHtml((object) ['payment_method_code' => 'offline_transfer', 'number' => 'OT-1003'], $settings)
        );
    }

    public function test_transfer_payment_uses_native_order_confirmation_only_when_core_order_mail_is_off(): void
    {
        $service = new CustomMailService;

        $this->assertTrue($service->shouldSendNativeOrderConfirmationForTransferPayment('offline_transfer', '', []));
        $this->assertTrue($service->shouldSendNativeOrderConfirmationForTransferPayment('western_union', 'smtp', []));
        $this->assertFalse($service->shouldSendNativeOrderConfirmationForTransferPayment('offline_transfer', 'smtp', ['order']));
        $this->assertFalse($service->shouldSendNativeOrderConfirmationForTransferPayment('paypal', '', []));
    }
}
