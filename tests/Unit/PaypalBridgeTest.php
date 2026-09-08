<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\TestCase;
use Plugin\PaypalA\Controllers\PaypalAController;
use Plugin\PaypalA\Services\BridgeSignature as ABridgeSignature;
use Plugin\PaypalA\Services\PaypalAMoney;
use Plugin\PaypalA\Services\PaypalAPaymentService;
use Plugin\PaypalB\Models\PaypalBAccount;
use Plugin\PaypalB\Services\BridgeSignature as BBridgeSignature;
use Plugin\PaypalB\Services\BridgeUrl as BBridgeUrl;
use Plugin\PaypalB\Services\PaypalBAccountSelector;
use Plugin\PaypalB\Services\PaypalBApiException;
use Plugin\PaypalB\Services\PaypalBConfiguration;
use Plugin\PaypalB\Services\PaypalBMoney;
use Plugin\PaypalB\Services\PaypalBOrderPayload;
use Plugin\PaypalB\Services\PaypalBTransactionService;

class PaypalBridgeTest extends TestCase
{
    public function test_a_and_b_generate_the_same_canonical_signature(): void
    {
        $payload = [
            'reference' => 'REF-1',
            'amount'    => '18.70',
            'nested'    => ['z' => 1, 'a' => ['two', 'one']],
        ];
        $secret    = str_repeat('s', 48);
        $timestamp = '1787563200';
        $nonce     = str_repeat('ab', 24);

        $this->assertSame(
            ABridgeSignature::signature($payload, $secret, $timestamp, $nonce),
            BBridgeSignature::signature($payload, $secret, $timestamp, $nonce)
        );
        $this->assertSame(
            ABridgeSignature::canonicalJson($payload),
            '{"amount":"18.70","nested":{"a":["two","one"],"z":1},"reference":"REF-1"}'
        );
    }

    public function test_paypal_money_keeps_currency_precision_and_rejects_real_extra_digits(): void
    {
        $this->assertSame('18.70', PaypalAMoney::normalize('18.7', 'USD'));
        $this->assertSame('18.70', PaypalBMoney::normalize('18.7000', 'USD'));
        $this->assertSame('100', PaypalAMoney::normalize('100', 'JPY'));
        $this->assertTrue(PaypalBMoney::equal('18.70', '18.7', 'USD'));

        $rejected = false;

        try {
            PaypalAMoney::normalize('18.701', 'USD');
        } catch (\Throwable) {
            // 纯单元环境不启动 Laravel 容器；这里只验证非法金额一定不会被接受。
            $rejected = true;
        }
        $this->assertTrue($rejected, '应拒绝超过币种精度的金额。');
    }

    public function test_business_and_personal_seller_api_accounts_are_eligible_for_automatic_collection(): void
    {
        $business = new PaypalBAccount(['account_type' => PaypalBAccount::TYPE_BUSINESS_API]);
        $personal = new PaypalBAccount(['account_type' => PaypalBAccount::TYPE_PERSONAL_SELLER_API]);
        $legacy   = new PaypalBAccount(['account_type' => PaypalBAccount::TYPE_PERSONAL_API]);

        $this->assertTrue($business->usesApi());
        $this->assertTrue($personal->usesApi());
        $this->assertFalse($legacy->usesApi());
        $this->assertSame([
            PaypalBAccount::TYPE_BUSINESS_API,
            PaypalBAccount::TYPE_PERSONAL_SELLER_API,
        ], PaypalBAccount::automaticApiAccountTypes());

        $business->forceFill(['wallet_enabled' => true, 'card_enabled' => false]);
        $personal->forceFill(['wallet_enabled' => true, 'card_enabled' => true]);
        $this->assertTrue($business->supportsPaymentMethods(['wallet']));
        $this->assertFalse($business->supportsPaymentMethods(['card']));
        $this->assertTrue($personal->supportsPaymentMethods(['card']));
    }

    public function test_account_pool_rotates_between_business_and_personal_seller_accounts(): void
    {
        $selector = new PaypalBAccountSelector(10);
        $method   = new \ReflectionMethod($selector, 'chooseNextEligibleAccount');
        $method->setAccessible(true);
        $business = new PaypalBAccount([
            'account_type'         => PaypalBAccount::TYPE_BUSINESS_API,
            'active'               => true,
            'supported_currencies' => 'USD',
            'wallet_enabled'       => true,
            'priority'             => 10,
        ]);
        $business->forceFill(['id' => 10]);
        $personal = new PaypalBAccount([
            'account_type'         => PaypalBAccount::TYPE_PERSONAL_SELLER_API,
            'active'               => true,
            'supported_currencies' => 'USD',
            'wallet_enabled'       => true,
            'priority'             => 20,
        ]);
        $personal->forceFill(['id' => 20]);
        $cooling = new class extends PaypalBAccount
        {
            public function getDateFormat()
            {
                return 'Y-m-d H:i:s';
            }
        };
        $cooling->forceFill([
            'account_type'           => PaypalBAccount::TYPE_BUSINESS_API,
            'active'                 => true,
            'supported_currencies'   => 'USD',
            'wallet_enabled'         => true,
            'priority'               => 30,
            'failure_cooldown_until' => now()->addMinute(),
        ]);
        $cooling->forceFill(['id' => 30]);
        $legacy = new PaypalBAccount([
            'account_type'         => PaypalBAccount::TYPE_PERSONAL_API,
            'active'               => true,
            'supported_currencies' => 'USD',
            'wallet_enabled'       => true,
            'priority'             => 40,
        ]);
        $legacy->forceFill(['id' => 40]);

        $accounts = [$business, $personal, $cooling, $legacy];

        $this->assertSame($business, $method->invoke($selector, $accounts, 'USD', ['wallet'], null));
        $this->assertSame($personal, $method->invoke($selector, $accounts, 'USD', ['wallet'], 10));
        $this->assertSame($business, $method->invoke($selector, $accounts, 'USD', ['wallet'], 20));
    }

    public function test_wallet_and_card_switches_are_normalized_and_card_is_off_by_default_on_b(): void
    {
        $configuration = PaypalBConfiguration::from([
            'public_url'                    => 'https://pay.example.test',
            'a_site_url'                    => 'https://shop.example.test',
            'a_request_verification_secret' => str_repeat('a', 32),
            'a_callback_signing_secret'     => str_repeat('b', 32),
            'request_timeout_seconds'       => 10,
            'account_cooldown_minutes'      => 10,
            'merchant_display_name'         => 'B Merchant LLC',
            'customer_service_email'        => 'support@pay.example.test',
            'customer_service_phone'        => '+1 555 0100',
            'refund_policy_url'             => 'https://pay.example.test/refunds',
            'privacy_policy_url'            => 'https://pay.example.test/privacy',
            'terms_url'                     => 'https://pay.example.test/terms',
        ]);

        $this->assertTrue($configuration['wallet_enabled']);
        $this->assertFalse($configuration['card_enabled']);
        $this->assertSame('hosted', $configuration['checkout_mode']);
    }

    public function test_local_http_configuration_accepts_same_origin_policy_urls(): void
    {
        $configuration = PaypalBConfiguration::from([
            'public_url'                      => 'http://pay.example.test:8081',
            'a_site_url'                      => 'http://shop.example.test:8080',
            'a_request_verification_secret'   => str_repeat('a', 32),
            'a_callback_signing_secret'       => str_repeat('b', 32),
            'request_timeout_seconds'         => 10,
            'account_cooldown_minutes'        => 10,
            'merchant_display_name'           => 'B Merchant LLC',
            'customer_service_email'          => 'support@pay.example.test',
            'refund_policy_url'               => 'http://pay.example.test:8081/refunds',
            'privacy_policy_url'              => 'http://pay.example.test:8081/privacy',
            'terms_url'                       => 'http://pay.example.test:8081/terms',
        ]);

        $this->assertSame('http://pay.example.test:8081', $configuration['public_url']);
        $this->assertSame('http://pay.example.test:8081/refunds', $configuration['refund_policy_url']);
    }

    public function test_paypal_a_item_source_accepts_real_and_mapped_items(): void
    {
        $settings = [
            'public_url'                   => 'https://shop.example.test',
            'b_site_url'                   => 'https://pay.example.test',
            'request_signing_secret'       => str_repeat('a', 32),
            'callback_verification_secret' => str_repeat('b', 32),
            'payment_expiration_minutes'   => 30,
            'request_timeout_seconds'      => 10,
        ];

        $this->assertSame(
            PaypalAPaymentService::ITEM_SOURCE_A_ORDER,
            PaypalAPaymentService::bridgeConfiguration($settings)['bridge_item_source']
        );
        $this->assertSame(
            PaypalAPaymentService::ITEM_SOURCE_MAPPING,
            PaypalAPaymentService::bridgeConfiguration($settings + ['bridge_item_source' => 'mapped'])['bridge_item_source']
        );
    }

    public function test_b_accepts_a_mapped_projection_but_preserves_the_real_order_snapshot(): void
    {
        $service = new PaypalBTransactionService([
            'a_site_url'     => 'https://shop.example.test',
            'card_enabled'   => false,
            'wallet_enabled' => true,
        ]);
        $validator = new \ReflectionMethod(PaypalBTransactionService::class, 'validateCreatePayload');
        $validator->setAccessible(true);
        $payload = $validator->invoke($service, [
            'reference'           => 'REF-MAPPED-1001',
            'order_number'        => 'ORDER-MAPPED-1001',
            'amount'              => '20.00',
            'currency'            => 'USD',
            'expires_at'          => now()->addMinutes(10)->toIso8601String(),
            'callback_url'        => 'https://shop.example.test/api/paypal-a/bridge/callback',
            'allowed_methods'     => ['wallet'],
            'buyer'               => [
                'name'            => 'Buyer',
                'email'           => 'buyer@example.test',
                'calling_code'    => '+1',
                'telephone'       => '5550100',
                'ip'              => '203.0.113.10',
                'user_agent'      => 'Mozilla/5.0',
                'comment'         => '',
                'billing_address' => [],
            ],
            'order_items'         => [[
                'source_product_id' => 10,
                'name'              => '真实商品',
                'sku'               => 'REAL-SKU-10',
                'quantity'          => 2,
                'unit_price'        => '10.0000',
                'line_total'        => '20.0000',
            ]],
            'paypal_item_source'  => 'mapped',
            'paypal_order_items'  => [[
                'source_product_id' => 10,
                'name'              => '映射商品',
                'sku'               => 'PUBLIC-SKU-10',
                'quantity'          => 2,
                'unit_price'        => '10.0000',
                'line_total'        => '20.0000',
            ]],
            'order_totals'        => [
                ['code' => 'sub_total', 'title' => 'Subtotal', 'value' => '20.0000'],
                ['code' => 'order_total', 'title' => 'Total', 'value' => '20.0000'],
            ],
            'shipping_address'    => ['required' => false],
            'fulfillment'         => [],
            'order_snapshot'      => [
                'number'               => 'ORDER-MAPPED-1001',
                'currency'             => 'USD',
                'amount'               => '20.00',
                'shipping_required'    => false,
                'shipping_method_code' => '',
                'shipping_method_name' => '',
                'created_at'           => now()->toIso8601String(),
                'updated_at'           => now()->toIso8601String(),
            ],
        ]);

        $this->assertSame('mapped', $payload['paypal_item_source']);
        $this->assertSame('真实商品', $payload['order_items'][0]['name']);
        $this->assertSame('映射商品', $payload['paypal_order_items'][0]['name']);
        $this->assertSame('PUBLIC-SKU-10', $payload['paypal_order_items'][0]['sku']);
    }

    public function test_paypal_blade_templates_compile(): void
    {
        $compiler = new BladeCompiler(new Filesystem, sys_get_temp_dir());
        $compiler->withoutComponentTags();
        foreach ([
            'plugins/PaypalA/Views/checkout/payment.blade.php',
            'plugins/PaypalA/Views/admin/config_form.blade.php',
            'plugins/PaypalB/Views/checkout/card.blade.php',
            'plugins/PaypalB/Views/checkout/wallet.blade.php',
            'plugins/PaypalB/Views/checkout/summary.blade.php',
            'plugins/PaypalB/Views/checkout/error.blade.php',
            'plugins/PaypalB/Views/admin/config_form.blade.php',
            'plugins/PaypalB/Views/admin/account_fields.blade.php',
            'plugins/PaypalB/Views/admin/accounts.blade.php',
        ] as $path) {
            $compiled = $compiler->compileString(file_get_contents($path));

            $this->assertNotSame('', $compiled, $path);
        }
    }

    public function test_bridge_secret_settings_are_persistent_and_can_be_revealed(): void
    {
        $aTemplate = file_get_contents('plugins/PaypalA/Views/admin/config_form.blade.php');
        $bTemplate = file_get_contents('plugins/PaypalB/Views/admin/config_form.blade.php');
        $aAdmin    = file_get_contents('plugins/PaypalA/Controllers/PaypalAAdminController.php');
        $bAdmin    = file_get_contents('plugins/PaypalB/Controllers/PaypalBAdminController.php');

        foreach ([
            [$aTemplate, "\$value('request_signing_secret')", 'paypal-a-request-signing-secret'],
            [$aTemplate, "\$value('callback_verification_secret')", 'paypal-a-callback-verification-secret'],
            [$bTemplate, "\$value('a_request_verification_secret')", 'paypal-b-request-verification-secret'],
            [$bTemplate, "\$value('a_callback_signing_secret')", 'paypal-b-callback-signing-secret'],
        ] as [$template, $value, $inputId]) {
            $this->assertStringContainsString($value, $template);
            $this->assertStringContainsString('type="password"', $template);
            $this->assertStringContainsString("data-password-toggle=\"{$inputId}\"", $template);
            $this->assertStringContainsString('bi bi-eye', $template);
            $this->assertStringContainsString("id=\"{$inputId}\"", $template);
        }

        $this->assertSame(1, substr_count($aTemplate, "button.setAttribute('aria-label', label);"));
        $this->assertSame(1, substr_count($bTemplate, "button.setAttribute('aria-label', label);"));
        $this->assertStringContainsString("input.type = showPassword ? 'text' : 'password';", $aTemplate);
        $this->assertStringContainsString("input.type = showPassword ? 'text' : 'password';", $bTemplate);
        $this->assertStringContainsString("foreach (['request_signing_secret', 'callback_verification_secret'] as \$secret)", $aAdmin);
        $this->assertStringContainsString("foreach (['a_request_verification_secret', 'a_callback_signing_secret'] as \$secret)", $bAdmin);
        $this->assertSame(1, substr_count($aAdmin, 'SettingRepo::update'));
        $this->assertSame(1, substr_count($bAdmin, 'SettingRepo::update'));
    }

    public function test_paypal_b_settings_expose_api_credential_management(): void
    {
        $settingsTemplate = file_get_contents('plugins/PaypalB/Views/admin/config_form.blade.php');
        $accountTemplate  = file_get_contents('plugins/PaypalB/Views/admin/account_fields.blade.php');
        $accountsTemplate = file_get_contents('plugins/PaypalB/Views/admin/accounts.blade.php');
        $adminController  = file_get_contents('plugins/PaypalB/Controllers/PaypalBAdminController.php');

        $this->assertStringContainsString("admin_route('paypal_b.accounts.index')", $settingsTemplate);
        $this->assertStringContainsString('管理收款账号与 API 凭证', $settingsTemplate);
        $this->assertStringContainsString('name="client_id"', $accountTemplate);
        $this->assertStringContainsString('name="client_secret"', $accountTemplate);
        $this->assertStringContainsString('name="webhook_id"', $accountTemplate);
        $this->assertStringContainsString("plugin_setting('paypal_b', [])", $adminController);
        $this->assertStringContainsString("'webhook_base_url' => \$publicUrl === '' ? null", $adminController);
        $this->assertStringContainsString('请先在插件配置中保存 B 站公网地址', $accountsTemplate);
    }

    public function test_paypal_b_configuration_exposes_privacy_policy_url(): void
    {
        $settingsTemplate = file_get_contents('plugins/PaypalB/Views/admin/config_form.blade.php');
        $summaryTemplate  = file_get_contents('plugins/PaypalB/Views/checkout/summary.blade.php');
        $errorTemplate    = file_get_contents('plugins/PaypalB/Views/checkout/error.blade.php');
        $policyTemplate   = file_get_contents('plugins/PaypalB/Views/checkout/policy.blade.php');
        $routes           = file_get_contents('plugins/PaypalB/Routes/shop.php');
        $columns          = file_get_contents('plugins/PaypalB/columns.php');
        $service          = file_get_contents('plugins/PaypalB/Services/PaypalBConfiguration.php');

        $this->assertStringContainsString('name="privacy_policy_url"', $settingsTemplate);
        $this->assertStringContainsString('隐私政策', $settingsTemplate);
        $this->assertStringContainsString('privacy_url', $summaryTemplate);
        $this->assertStringContainsString('隐私政策', $summaryTemplate);
        $this->assertStringContainsString('privacy_url', $errorTemplate);
        $this->assertStringContainsString('退款政策', $errorTemplate);
        $this->assertStringContainsString('交易条款', $errorTemplate);
        $this->assertStringContainsString('policyPage', $routes . file_get_contents('plugins/PaypalB/Controllers/PaypalBController.php'));
        $this->assertStringContainsString('站内可访问', $policyTemplate);
        $this->assertStringContainsString('隐私政策', $policyTemplate);
        $this->assertStringContainsString('交易条款', $policyTemplate);
        $this->assertStringContainsString('privacy_policy_url', $columns);
        $this->assertStringContainsString('privacy_policy_url', $service);
    }

    public function test_card_fields_passes_client_token_through_sdk_data_attribute(): void
    {
        $template = file_get_contents('plugins/PaypalB/Views/checkout/card.blade.php');

        $this->assertStringContainsString('data-client-token="{{ $client_token }}"', $template);
        $this->assertStringNotContainsString("'client-token' => \$client_token", $template);
    }

    public function test_checkout_capture_token_is_bound_to_the_paypal_session(): void
    {
        $service = new PaypalBTransactionService([
            'a_callback_signing_secret' => str_repeat('c', 48),
        ]);
        $transaction = new \Plugin\PaypalB\Models\PaypalBTransaction;
        $transaction->forceFill([
            'transaction_id'  => 'PB-TEST-1',
            'public_token'    => str_repeat('p', 64),
            'paypal_order_id' => 'PAYPAL-ORDER-1',
            'reference'       => 'REF-1',
        ]);

        $first = $service->captureToken($transaction);
        $this->assertSame(64, strlen($first));
        $this->assertSame($first, $service->captureToken($transaction));

        $transaction->paypal_order_id = 'PAYPAL-ORDER-2';
        $this->assertNotSame($first, $service->captureToken($transaction));
    }

    public function test_capture_and_card_fields_bind_to_the_original_paypal_application(): void
    {
        $service = new PaypalBTransactionService(['sandbox_mode' => true]);
        $account = new PaypalBAccount(['client_id' => 'client-original']);
        $account->forceFill(['id' => 7]);
        $transaction = new \Plugin\PaypalB\Models\PaypalBTransaction;
        $transaction->forceFill([
            'account_fingerprint' => hash('sha256', '7|client-original|sandbox'),
        ]);

        $method = new \ReflectionMethod($service, 'accountFingerprint');
        $method->setAccessible(true);
        $this->assertSame($transaction->account_fingerprint, $method->invoke($service, $account));

        $account->client_id = 'client-replaced';
        $this->assertNotSame($transaction->account_fingerprint, $method->invoke($service, $account));

        $serviceSource = file_get_contents('plugins/PaypalB/Services/PaypalBTransactionService.php');
        $this->assertSame(2, substr_count($serviceSource, '$this->assertAccountApplication($transaction, $account);'));
    }

    public function test_terminal_uncaptured_attempts_do_not_block_a_new_paypal_session(): void
    {
        $service = new PaypalBTransactionService([]);
        $method  = new \ReflectionMethod($service, 'isTerminalUncapturedAttempt');
        $method->setAccessible(true);

        foreach (['cancelled', 'failed', 'expired'] as $status) {
            $transaction = new \Plugin\PaypalB\Models\PaypalBTransaction([
                'status'          => $status,
                'paypal_order_id' => 'PAYPAL-ORDER-OLD',
            ]);
            $this->assertTrue($method->invoke($service, $transaction));
        }

        $pending = new \Plugin\PaypalB\Models\PaypalBTransaction([
            'status'          => 'pending',
            'paypal_order_id' => 'PAYPAL-ORDER-PENDING',
        ]);
        $this->assertFalse($method->invoke($service, $pending));

        $captured = new \Plugin\PaypalB\Models\PaypalBTransaction([
            'status'            => 'cancelled',
            'paypal_order_id'   => 'PAYPAL-ORDER-CAPTURED',
            'paypal_capture_id' => 'PAYPAL-CAPTURE-OLD',
        ]);
        $this->assertFalse($method->invoke($service, $captured));
    }

    public function test_bridge_urls_keep_public_host_and_reject_untrusted_callbacks(): void
    {
        $this->assertSame(
            'https://pay.example.test/paypal-b/checkout/token-1',
            BBridgeUrl::join('https://pay.example.test/', '/paypal-b/checkout/token-1')
        );
        $this->assertTrue(BBridgeUrl::isAllowedCallback(
            'https://shop.example.test/api/paypal-a/bridge/callback',
            'https://shop.example.test'
        ));
        $this->assertFalse(BBridgeUrl::isAllowedCallback(
            'https://other.example.test/api/paypal-a/bridge/callback',
            'https://shop.example.test'
        ));
    }

    public function test_real_physical_order_payload_contains_items_breakdown_and_shipping_address(): void
    {
        $payload = PaypalBOrderPayload::buildFromData([
            'reference'    => 'REF-PHYSICAL-1',
            'order_number' => 'ORDER-1001',
            'amount'       => '23.00',
            'currency'     => 'USD',
            'order_items'  => [[
                'name'       => '真实商品名称',
                'sku'        => 'REAL-SKU-1',
                'quantity'   => 2,
                'unit_price' => '10.0000',
                'line_total' => '20.0000',
            ]],
            'order_totals' => [
                ['code' => 'sub_total', 'value' => '20.0000'],
                ['code' => 'shipping', 'value' => '5.0000'],
                ['code' => 'customer_discount', 'value' => '-2.0000'],
                ['code' => 'order_total', 'value' => '23.0000'],
            ],
            'shipping_address' => [
                'required'     => true,
                'name'         => 'Buyer Name',
                'address_1'    => '1 Main Street',
                'address_2'    => '',
                'city'         => 'San Jose',
                'zone'         => 'CA',
                'postal_code'  => '95131',
                'country_code' => 'US',
            ],
        ], 'https://pay.example.test/return', 'https://pay.example.test/cancel', 'B Merchant LLC');

        $unit = $payload['purchase_units'][0];
        $this->assertSame('SET_PROVIDED_ADDRESS', $payload['application_context']['shipping_preference']);
        $this->assertSame('真实商品名称', $unit['items'][0]['name']);
        $this->assertSame('REAL-SKU-1', $unit['items'][0]['sku']);
        $this->assertSame('5.00', $unit['amount']['breakdown']['shipping']['value']);
        $this->assertSame('2.00', $unit['amount']['breakdown']['discount']['value']);
        $this->assertSame('US', $unit['shipping']['address']['country_code']);
    }

    public function test_only_connection_and_paypal_5xx_errors_are_retryable(): void
    {
        $connection  = new PaypalBApiException('connection', null, 'CONNECTION_ERROR', [], true);
        $serverError = new PaypalBApiException('server', 503, 'INTERNAL_SERVER_ERROR', [], true);
        $rateLimited = new PaypalBApiException('rate limited', 429, 'RATE_LIMIT_REACHED', [], true);
        $rejected    = new PaypalBApiException('rejected', 422, 'UNPROCESSABLE_ENTITY', ['COMPLIANCE_VIOLATION'], false);

        $this->assertTrue($connection->retryable);
        $this->assertSame('connection', $connection->failureClass());
        $this->assertTrue($serverError->retryable);
        $this->assertSame('provider_5xx', $serverError->failureClass());
        $this->assertTrue($rateLimited->retryable);
        $this->assertSame('provider_transient', $rateLimited->failureClass());
        $this->assertFalse($rejected->retryable);
        $this->assertSame('provider_rejected', $rejected->failureClass());
    }

    public function test_paypal_order_payload_rejects_amount_mismatch(): void
    {
        $rejected = false;

        try {
            PaypalBOrderPayload::buildFromData([
                'reference'        => 'REF-MISMATCH-1',
                'order_number'     => 'ORDER-1003',
                'amount'           => '12.00',
                'currency'         => 'USD',
                'order_items'      => [[
                    'name'       => '真实商品',
                    'sku'        => 'REAL-1003',
                    'quantity'   => 1,
                    'unit_price' => '10.0000',
                    'line_total' => '10.0000',
                ]],
                'order_totals'     => [
                    ['code' => 'sub_total', 'value' => '10.0000'],
                    ['code' => 'order_total', 'value' => '10.0000'],
                ],
                'shipping_address' => ['required' => false],
            ], 'https://pay.example.test/return', 'https://pay.example.test/cancel', 'B Merchant LLC');
        } catch (\Throwable) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'PayPal 请求体不得接受与商品/费用明细不一致的订单总额。');
    }

    public function test_paypal_money_and_payload_addition_reject_integer_overflow(): void
    {
        $multiplyRejected = false;

        try {
            PaypalBMoney::multiply('999999999999.99', PHP_INT_MAX, 'USD');
        } catch (\Throwable) {
            $multiplyRejected = true;
        }
        $this->assertTrue($multiplyRejected, '商品单价乘数量溢出时必须拒绝请求。');

        $add = new \ReflectionMethod(PaypalBOrderPayload::class, 'addMinor');
        $add->setAccessible(true);
        $additionRejected = false;

        try {
            $add->invoke(null, PHP_INT_MAX, 1, 'order_totals');
        } catch (\Throwable) {
            $additionRejected = true;
        }
        $this->assertTrue($additionRejected, '订单费用合计溢出时必须拒绝请求。');
    }

    public function test_configuration_and_malformed_provider_responses_have_distinct_reconciliation_classes(): void
    {
        $configuration = PaypalBApiException::configuration('missing credentials');
        $malformed     = PaypalBApiException::malformedResponse('malformed response', 200);

        $this->assertFalse($configuration->retryable);
        $this->assertSame('configuration', $configuration->failureClass());
        $this->assertTrue($malformed->retryable);
        $this->assertSame('provider_unknown', $malformed->failureClass());
    }

    public function test_a_site_allows_completed_to_upgrade_a_late_non_completed_state(): void
    {
        $controller = new PaypalAController;
        $method     = new \ReflectionMethod($controller, 'shouldApplyRemoteStatus');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($controller, 'failed', 'pending'));
        $this->assertFalse($method->invoke($controller, 'pending', 'reconciling'));
        $this->assertTrue($method->invoke($controller, 'failed', 'completed'));
        $this->assertFalse($method->invoke($controller, 'expired', 'completed'));
        $this->assertFalse($method->invoke($controller, 'completed', 'failed'));
    }

    public function test_completed_payment_for_cancelled_order_is_still_audited(): void
    {
        $controller = file_get_contents('plugins/PaypalA/Controllers/PaypalAController.php');

        $this->assertStringContainsString('use Beike\\Repositories\\OrderPaymentRepo;', $controller);
        $this->assertStringContainsString('elseif ($order->status === StateMachineService::CANCELLED)', $controller);
        $this->assertStringContainsString('OrderPaymentRepo::createOrUpdatePayment($order->id, $paymentAuditData);', $controller);
    }

    public function test_paypal_a_start_does_not_hide_http_errors_or_leak_unexpected_exceptions(): void
    {
        $controller = file_get_contents('plugins/PaypalA/Controllers/PaypalAController.php');
        $client     = file_get_contents('plugins/PaypalA/Services/PaypalABridgeClient.php');

        $this->assertStringContainsString('use Symfony\\Component\\HttpKernel\\Exception\\HttpExceptionInterface;', $controller);
        $this->assertStringContainsString('catch (HttpExceptionInterface $exception)', $controller);
        $this->assertStringContainsString('->withErrors($exception->errors())', $controller);
        $this->assertStringContainsString("->withErrors('支付暂时无法发起，请稍后重试。')", $controller);
        $this->assertStringContainsString('report($exception);', $controller);
        $this->assertStringNotContainsString('effectiveUri()?->toString()', $client);
        $this->assertStringContainsString('(string) ($response->effectiveUri()', $client);
    }

    public function test_paypal_completion_paths_reject_expired_transactions(): void
    {
        $service       = file_get_contents('plugins/PaypalB/Services/PaypalBTransactionService.php');
        $markCompleted = substr($service, strpos($service, 'private function markCompleted('));

        $this->assertStringContainsString("\$locked->status === 'expired'", $markCompleted);
        $this->assertStringContainsString('$locked->expires_at && $locked->expires_at->isPast()', $markCompleted);
        $this->assertStringContainsString('支付会话已过期，不能再确认收款。', $markCompleted);
    }

    public function test_paypal_callback_does_not_finalize_a_stale_status_snapshot(): void
    {
        $service = file_get_contents('plugins/PaypalB/Services/PaypalBCallbackService.php');

        $this->assertStringContainsString('$snapshotStatus = (string) $payload[\'status\'];', $service);
        $this->assertSame(3, substr_count($service, '(string) $locked->status !== $snapshotStatus'));
        $this->assertSame(2, substr_count($service, '(int) $locked->callback_attempts !== $attempt'));
        $this->assertStringContainsString("data_get(\$current, \$field, '')", $service);
        $this->assertStringContainsString('$this->callbackPayloadMatches((array) $locked->callback_payload, $payload)', $service);
        $this->assertStringContainsString('private function requeueForLatestStatus(', $service);
        $this->assertStringContainsString("'callback_status'          => 'pending'", $service);
        $this->assertStringContainsString("'callback_next_attempt_at' => now()", $service);
    }

    public function test_buyer_ip_and_user_agent_are_validated_before_risk_audit(): void
    {
        $service = new PaypalBTransactionService([]);
        $ip      = new \ReflectionMethod($service, 'validateIp');
        $ip->setAccessible(true);
        $ua = new \ReflectionMethod($service, 'validateUserAgent');
        $ua->setAccessible(true);

        $this->assertSame('2001:db8::1', $ip->invoke($service, '2001:0db8:0:0:0:0:0:1', 'buyer.ip'));
        $this->assertSame('Mozilla/5.0 PayPal Checkout', $ua->invoke($service, ' Mozilla/5.0 PayPal Checkout ', 'buyer.user_agent'));

        foreach ([
            ['method' => $ip, 'value' => 'not-an-ip', 'field' => 'buyer.ip'],
            ['method' => $ip, 'value' => '0.0.0.0', 'field' => 'buyer.ip'],
            ['method' => $ua, 'value' => "Mozilla/5.0\x00", 'field' => 'buyer.user_agent'],
        ] as $case) {
            $rejected = false;

            try {
                $case['method']->invoke($service, $case['value'], $case['field']);
            } catch (\Throwable) {
                $rejected = true;
            }
            $this->assertTrue($rejected, '非法买家风险信号必须被拒绝。');
        }
    }

    public function test_paypal_refund_webhook_settles_existing_refund_and_updates_the_callback_state(): void
    {
        $service   = file_get_contents('plugins/PaypalB/Services/PaypalBRefundService.php');
        $migration = file_get_contents('plugins/PaypalB/Migrations/2026_08_27_000007_create_paypal_b_refunds.php');

        $this->assertStringContainsString('private function settleCompletedRefundLocked(', $service);
        $this->assertSame(3, substr_count($service, '$this->settleCompletedRefundLocked('));
        $this->assertStringContainsString('where(\'provider_refund_id\', $providerRefundId)', $service);
        $this->assertStringContainsString('PaypalBMoney::equal($existing->amount, $amount, $currency)', $service);
        $this->assertStringContainsString("'refunded_amount'           => PaypalBMoney::fromMinor", $service);
        $this->assertStringContainsString("'payment_exception_status'  => null", $service);
        $this->assertStringContainsString('paypal_b_refunds_provider_id_unique', $migration);
        $this->assertStringContainsString('paypal_b_refunds_provider_event_id_unique', $migration);
    }

    public function test_refund_and_dispute_webhooks_are_verified_and_processed_asynchronously(): void
    {
        $controller = file_get_contents('plugins/PaypalB/Controllers/PaypalBController.php');
        $service    = file_get_contents('plugins/PaypalB/Services/PaypalBTransactionService.php');
        $job        = file_get_contents('plugins/PaypalB/Jobs/PaypalBWebhookJob.php');

        foreach (['PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED', 'CUSTOMER.DISPUTE.CREATED', 'CUSTOMER.DISPUTE.RESOLVED'] as $eventType) {
            $this->assertStringContainsString($eventType, $service);
        }
        $this->assertStringContainsString('verifyWebhookSignature', $controller);
        $this->assertStringContainsString('PaypalBWebhookJob::dispatch', $controller);
        $this->assertStringContainsString('applyWebhook($transaction, $payload)', $job);
        $this->assertStringContainsString("'dispute_status'  => \$incomingStatus", $service);
    }

    public function test_risk_rate_limit_uses_database_cache_and_only_stores_irreversible_indexes(): void
    {
        $service = file_get_contents('plugins/PaypalB/Services/PaypalBTransactionService.php');

        $this->assertStringContainsString("Cache::store('database')", $service);
        $this->assertStringContainsString('RISK_RATE_LIMIT_MAX_ATTEMPTS', $service);
        $this->assertStringContainsString('buyer_email_hash', $service);
        $this->assertStringContainsString('buyer_ip_hash', $service);
        $this->assertStringContainsString('risk_fingerprint', $service);
        $this->assertStringContainsString('increment($key)', $service);
        $this->assertStringContainsString('return hash_hmac', $service);
    }

    public function test_hosted_checkout_rejects_iframe_mode(): void
    {
        $aController = file_get_contents('plugins/PaypalA/Controllers/PaypalAController.php');
        $bController = file_get_contents('plugins/PaypalB/Controllers/PaypalBController.php');
        $aRoutes     = file_get_contents('plugins/PaypalA/Routes/shop.php');

        $this->assertStringContainsString('redirect()->away($this->checkoutUrlWithMethod', $aController);
        $this->assertStringNotContainsString("'embed'  => '1'", $aController);
        $this->assertStringContainsString("frame-ancestors 'none'", $bController);
        $this->assertStringNotContainsString("request()->boolean('embed')", $bController);
        $this->assertStringContainsString("Route::post('orders/{number}/start'", $aRoutes);
        $this->assertStringNotContainsString("Route::match(['get', 'post']", $aRoutes);
    }

    public function test_paypal_bridge_checkout_pages_and_redirects_disable_referrer_leakage(): void
    {
        $aController = file_get_contents('plugins/PaypalA/Controllers/PaypalAController.php');
        $aTemplate   = file_get_contents('plugins/PaypalA/Views/checkout/payment.blade.php');
        $bController = file_get_contents('plugins/PaypalB/Controllers/PaypalBController.php');
        $walletView  = file_get_contents('plugins/PaypalB/Views/checkout/wallet.blade.php');
        $summaryView  = file_get_contents('plugins/PaypalB/Views/checkout/summary.blade.php');

        $this->assertStringContainsString("headers->set('Referrer-Policy', 'no-referrer')", $aController);
        $this->assertStringContainsString('referrerpolicy="no-referrer"', $aTemplate);
        $this->assertSame(3, substr_count($aController, "headers->set('Referrer-Policy', 'no-referrer')"));
        $this->assertStringContainsString("'Referrer-Policy'         => 'no-referrer'", $bController);
        $this->assertSame(2, substr_count($bController, "'Referrer-Policy'         => 'no-referrer'"));
        $this->assertStringContainsString('referrerpolicy="no-referrer"', $walletView);
        $this->assertStringContainsString('rel="noreferrer noopener"', $summaryView);
        $this->assertStringContainsString('rel="noreferrer"', $summaryView);
        $this->assertStringContainsString("headers->set('Referrer-Policy', 'no-referrer')", $bController);
        $this->assertSame(4, substr_count($bController, "headers->set('Referrer-Policy', 'no-referrer')"));
    }

    public function test_unknown_create_results_are_explicitly_reconcilable_and_not_treated_as_account_failover(): void
    {
        $service = file_get_contents('plugins/PaypalB/Services/PaypalBTransactionService.php');

        $this->assertStringContainsString("'status'", $service);
        $this->assertStringContainsString("'reconciling'", $service);
        $this->assertStringContainsString("'failure_class'", $service);
        $this->assertStringContainsString("'provider_unknown'", $service);
        $this->assertStringContainsString("'paypal_b:create:order:'", $service);
        $this->assertStringContainsString('PayPal-Request-Id', file_get_contents('plugins/PaypalB/Services/PaypalBApiClient.php'));
    }

    public function test_webhook_and_callback_are_durable_and_idempotent_by_design(): void
    {
        $controller = file_get_contents('plugins/PaypalB/Controllers/PaypalBController.php');
        $service    = file_get_contents('plugins/PaypalB/Services/PaypalBCallbackService.php');
        $migration  = file_get_contents('plugins/PaypalB/Migrations/2026_08_26_000003_harden_paypal_b_transactions.php');

        $this->assertStringContainsString("where('paypal_event_id', \$eventId)", $controller);
        $this->assertStringContainsString('PaypalBWebhookJob::dispatch', $controller);
        $this->assertStringContainsString('PaypalBCallbackJob::dispatch', $service);
        $this->assertStringContainsString('paypal_b_webhook_events_account_event_unique', $migration);
        $this->assertStringContainsString("'callback_status'          => 'pending'", $service);
    }

    public function test_paypal_b_dispatch_callback_schedule_is_guarded_by_plugin_enablement(): void
    {
        $kernel = file_get_contents('app/Console/Kernel.php');

        $guardPos   = strpos($kernel, 'if ($paypalB && $paypalB->getEnabled())');
        $commandPos = strpos($kernel, '$schedule->command(\'paypal-b:dispatch-callbacks\')->everyMinute();');

        $this->assertNotFalse($guardPos);
        $this->assertNotFalse($commandPos);
        $this->assertLessThan($commandPos, $guardPos);
        $this->assertStringContainsString('$paypalB = plugin(\'paypal_b\');', $kernel);
    }
}
