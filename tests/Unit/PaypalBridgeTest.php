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
use Plugin\PaypalB\Services\PaypalBLocale;
use Plugin\PaypalB\Services\PaypalBMoney;
use Plugin\PaypalB\Services\PaypalBOrderPayload;
use Plugin\PaypalB\Services\PaypalBTransactionService;
use Plugin\PaypalB\Services\PaypalBWebhookRegistrar;

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

    public function test_standalone_b_configuration_does_not_require_a_site_or_bridge_secrets(): void
    {
        $configuration = PaypalBConfiguration::from([
            'public_url'                => 'https://pay.example.test',
            'request_timeout_seconds'   => 10,
            'account_cooldown_minutes'  => 10,
            'merchant_display_name'     => 'B Merchant LLC',
            'customer_service_email'    => 'support@pay.example.test',
            'refund_policy_url'         => 'https://pay.example.test/refunds',
            'privacy_policy_url'        => 'https://pay.example.test/privacy',
            'terms_url'                 => 'https://pay.example.test/terms',
            'wallet_enabled'            => true,
            'bridge_enabled'            => false,
        ]);

        $this->assertFalse($configuration['bridge_enabled']);
        $this->assertSame('', $configuration['a_site_url']);
        $this->assertNotSame('', $configuration['transaction_signing_secret']);
        $this->assertNotSame('', $configuration['audit_signing_secret']);
    }

    public function test_policy_urls_are_optional_and_fall_back_to_builtin_pages(): void
    {
        $configuration = PaypalBConfiguration::from([
            'public_url'               => 'https://pay.example.test',
            'request_timeout_seconds'  => 10,
            'account_cooldown_minutes' => 10,
            'merchant_display_name'    => 'B Merchant LLC',
            'customer_service_email'   => 'support@pay.example.test',
            'bridge_enabled'           => false,
        ]);

        $this->assertSame('', $configuration['refund_policy_url']);
        $this->assertSame('', $configuration['privacy_policy_url']);
        $this->assertSame('', $configuration['terms_url']);

        $controller = file_get_contents('plugins/PaypalB/Controllers/PaypalBController.php');
        $this->assertStringContainsString("\$configuration['refund_policy_url'] ?? ''), 'shop.paypal_b.refunds'", $controller);
        $this->assertStringContainsString("\$configuration['terms_url'] ?? ''), 'shop.paypal_b.terms'", $controller);
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
            'bridge_enabled' => true,
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
            'plugins/PaypalB/Views/checkout/partials/wallet_buttons.blade.php',
            'plugins/PaypalB/Views/checkout/payment.blade.php',
            'plugins/PaypalB/Views/checkout/summary.blade.php',
            'plugins/PaypalB/Views/checkout/error.blade.php',
            'plugins/PaypalB/Views/checkout/policy.blade.php',
            'plugins/PaypalB/Views/layout/checkout.blade.php',
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
        $layoutTemplate   = file_get_contents('plugins/PaypalB/Views/layout/checkout.blade.php');
        $policyTemplate   = file_get_contents('plugins/PaypalB/Views/checkout/policy.blade.php');
        $routes           = file_get_contents('plugins/PaypalB/Routes/shop.php');
        $columns          = file_get_contents('plugins/PaypalB/columns.php');
        $service          = file_get_contents('plugins/PaypalB/Services/PaypalBConfiguration.php');

        $this->assertStringContainsString('name="privacy_policy_url"', $settingsTemplate);
        $this->assertStringContainsString('隐私政策', $settingsTemplate);
        $this->assertStringContainsString('privacy_url', $layoutTemplate);
        $this->assertStringContainsString("trans('PaypalB::common.privacy_policy')", $layoutTemplate);
        $this->assertStringContainsString('refund_url', $layoutTemplate);
        $this->assertStringContainsString("trans('PaypalB::common.refund_policy')", $layoutTemplate);
        $this->assertStringContainsString('terms_url', $layoutTemplate);
        $this->assertStringContainsString("trans('PaypalB::common.terms_policy')", $layoutTemplate);
        $this->assertStringContainsString('policyPage', $routes . file_get_contents('plugins/PaypalB/Controllers/PaypalBController.php'));
        $this->assertStringContainsString("trans('PaypalB::common.policy_available')", $policyTemplate);
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
        // 捕获、信用卡令牌、同账号重试和后台同步四条路径都必须绑定原收款应用。
        $this->assertSame(4, substr_count($serviceSource, '$this->assertAccountApplication($transaction, $account);'));
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
        $controller = $this->normalizeSourceLineEndings(file_get_contents('plugins/PaypalA/Controllers/PaypalAController.php'));

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
        $this->assertStringContainsString('->withErrors($buyerErrors)', $controller);
        $this->assertStringContainsString("->withErrors(trans('PaypalA::common.start_failed'))", $controller);
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

    public function test_hosted_checkout_only_allows_embedding_for_embedded_sessions_from_the_a_site(): void
    {
        $aController = file_get_contents('plugins/PaypalA/Controllers/PaypalAController.php');
        $bController = file_get_contents('plugins/PaypalB/Controllers/PaypalBController.php');
        $bService    = file_get_contents('plugins/PaypalB/Services/PaypalBTransactionService.php');
        $bModel      = file_get_contents('plugins/PaypalB/Models/PaypalBTransaction.php');
        $aRoutes     = file_get_contents('plugins/PaypalA/Routes/shop.php');
        $aTemplate   = file_get_contents('plugins/PaypalA/Views/checkout/embedded.blade.php');

        // 跳转模式仍是默认；表单提交依旧 302 到 B 站托管页。
        $this->assertStringContainsString('redirect()->away($checkoutUrl)', $aController);
        $this->assertStringContainsString("Route::post('orders/{number}/start'", $aRoutes);
        $this->assertStringNotContainsString("Route::match(['get', 'post']", $aRoutes);

        // frame-ancestors 按会话决定：默认 'none'，只有落库为 embedded 的会话才放开到 A 站 origin，且不读请求参数。
        $this->assertStringContainsString("return \$origin === '' ? \"'none'\" : \$origin;", $bController);
        $this->assertStringContainsString("'Content-Security-Policy' => 'frame-ancestors ' . \$this->frameAncestors(\$transaction)", $bController);
        $this->assertStringNotContainsString("request()->boolean('embed')", $bController);
        $this->assertStringNotContainsString("query('presentation')", $bController);
        $this->assertStringContainsString("(string) \$this->presentation === self::PRESENTATION_EMBEDDED", $bModel);
        $this->assertStringContainsString("'presentation'        => \$this->validatePresentation(\$payload['presentation'] ?? null)", $bService);
        $this->assertStringContainsString("self::EMBEDDED_NOT_ALLOWED", $bService);

        // 政策页永远不可嵌入。
        $this->assertStringContainsString("'Content-Security-Policy' => \"frame-ancestors 'none'\"", $bController);

        // A 站只接受配置的 B 站 origin 的消息，且消息只触发服务端状态查询。
        $this->assertStringContainsString('if (event.origin !== config.bSiteOrigin) return;', $aTemplate);
        $this->assertStringContainsString('data.reference !== state.reference', $aTemplate);
        $this->assertStringContainsString('window.location.assign(payload.redirect_url)', $aTemplate);
        $this->assertStringContainsString("Route::get('orders/{number}/status'", $aRoutes);
    }

    public function test_paypal_bridge_checkout_pages_and_redirects_disable_referrer_leakage(): void
    {
        $aController   = file_get_contents('plugins/PaypalA/Controllers/PaypalAController.php');
        $aTemplate     = file_get_contents('plugins/PaypalA/Views/checkout/payment.blade.php');
        $bController   = file_get_contents('plugins/PaypalB/Controllers/PaypalBController.php');
        $walletPartial = file_get_contents('plugins/PaypalB/Views/checkout/partials/wallet_buttons.blade.php');
        $layoutView    = file_get_contents('plugins/PaypalB/Views/layout/checkout.blade.php');

        $this->assertStringContainsString("headers->set('Referrer-Policy', 'no-referrer')", $aController);
        $this->assertStringContainsString('rel="noreferrer"', $aTemplate);
        $this->assertSame(3, substr_count($aController, "headers->set('Referrer-Policy', 'no-referrer')"));
        $this->assertStringContainsString("'Referrer-Policy'         => 'no-referrer'", $bController);
        $this->assertSame(2, substr_count($bController, "'Referrer-Policy'         => 'no-referrer'"));
        $this->assertStringContainsString('referrerpolicy="no-referrer"', $walletPartial);
        $this->assertStringContainsString('rel="noreferrer noopener"', $layoutView);
        $this->assertStringContainsString('rel="noreferrer"', $layoutView);
        $this->assertStringContainsString("headers->set('Referrer-Policy', 'no-referrer')", $bController);
        $this->assertSame(6, substr_count($bController, "headers->set('Referrer-Policy', 'no-referrer')"));
    }

    public function test_paypal_b_local_payment_reuses_native_guest_order_authorization(): void
    {
        $bootstrap          = file_get_contents('plugins/PaypalB/Bootstrap.php');
        $localPayment       = file_get_contents('plugins/PaypalB/Services/PaypalBLocalPaymentService.php');
        $localOrder         = file_get_contents('plugins/PaypalB/Services/PaypalBLocalOrderService.php');
        $orderController    = file_get_contents('beike/Shop/Http/Controllers/Account/OrderController.php');
        $checkout           = file_get_contents('beike/Shop/Http/Controllers/CheckoutController.php');
        $paypalBController  = file_get_contents('plugins/PaypalB/Controllers/PaypalBController.php');
        $paypalBRoutes      = file_get_contents('plugins/PaypalB/Routes/shop.php');

        $this->assertStringContainsString("'repo.plugin.payment_methods'", $bootstrap);
        $this->assertStringContainsString('PaypalBLocalPaymentService', $bootstrap);
        $this->assertStringContainsString('createLocal(', $localPayment);
        $this->assertStringContainsString('SOURCE_LOCAL', $localPayment);
        $this->assertStringContainsString('StateMachineService::PAID', $localOrder);
        $this->assertStringContainsString("session()->push('guest_order_numbers'", $checkout);
        $this->assertStringContainsString('isGuestOrderAuthorized', $orderController);
        $this->assertStringContainsString("session('guest_order_numbers'", $orderController);
        $this->assertStringContainsString("Route::get('local-return/{token}'", $paypalBRoutes);
        $this->assertStringContainsString("->where('source', PaypalBTransaction::SOURCE_LOCAL)", $paypalBController);
        $this->assertStringContainsString("session()->push('guest_order_numbers'", $paypalBController);
        $this->assertStringContainsString("route('shop.paypal_b.local_return'", $paypalBController);
    }

    public function test_paypal_b_local_payment_session_failure_renders_on_the_payment_page(): void
    {
        $bootstrap = file_get_contents('plugins/PaypalB/Bootstrap.php');
        $template  = file_get_contents('plugins/PaypalB/Views/checkout/payment.blade.php');

        $this->assertStringContainsString('catch (\\Illuminate\\Validation\\ValidationException $exception)', $bootstrap);
        $this->assertStringContainsString("\$data['paypal_b_error']", $bootstrap);
        $this->assertStringContainsString('local_payment_start_failed', $bootstrap);
        $this->assertStringContainsString('paypal_b_error', $template);
    }

    public function test_paypal_b_local_payment_renders_wallet_buttons_without_a_checkout_redirect(): void
    {
        $localPayment = file_get_contents('plugins/PaypalB/Services/PaypalBLocalPaymentService.php');
        $template     = file_get_contents('plugins/PaypalB/Views/checkout/payment.blade.php');
        $walletView   = file_get_contents('plugins/PaypalB/Views/checkout/wallet.blade.php');
        $partial      = file_get_contents('plugins/PaypalB/Views/checkout/partials/wallet_buttons.blade.php');
        $include      = "@include('PaypalB::checkout.partials.wallet_buttons', ['wallet' => \$wallet])";

        $this->assertStringContainsString("'paypal_order_id'", $localPayment);
        $this->assertStringContainsString("'capture_url'", $localPayment);
        $this->assertStringContainsString("'return_url'", $localPayment);
        // 本地支付页与托管钱包页共用同一份按钮/捕获脚本，行为不允许各自漂移。
        $this->assertStringContainsString($include, $template);
        $this->assertStringContainsString($include, $walletView);
        $this->assertStringNotContainsString('window.paypal.Buttons', $template);
        $this->assertStringNotContainsString('window.paypal.Buttons', $walletView);
        $this->assertMatchesRegularExpression("/'credentials'\s+=>\s+'same-origin'/", $template);
        $this->assertStringContainsString('paypal-b-wallet-buttons', $partial);
        $this->assertStringContainsString('window.paypal.Buttons', $partial);
        $this->assertStringContainsString('createOrder: () => Promise.resolve(paypalOrderId)', $partial);
        $this->assertStringContainsString('captureUrl', $partial);
        $this->assertStringContainsString('window.location.assign(returnUrl)', $partial);
        $this->assertStringNotContainsString('href="{{ $paypal_b_instruction[\'checkout_url\'] }}"', $template);
    }

    public function test_paypal_b_has_idempotent_source_repair_migration(): void
    {
        $migration = file_get_contents('plugins/PaypalB/Migrations/2026_09_09_000013_repair_source_on_paypal_b_transactions.php');

        $this->assertStringContainsString("Schema::hasColumn('paypal_b_transactions', 'source')", $migration);
        $this->assertStringContainsString("\$table->string('source', 16)", $migration);
        $this->assertStringContainsString('paypal_b_transactions_source_index', $migration);
    }

    public function test_paypal_b_locale_helper_maps_site_locales_to_paypal_and_html_tags(): void
    {
        $this->assertSame('zh_CN', PaypalBLocale::paypalLocale('zh_cn'));
        $this->assertSame('zh_HK', PaypalBLocale::paypalLocale('zh_hk'));
        $this->assertSame('pt_BR', PaypalBLocale::paypalLocale('pt-BR'));
        $this->assertSame('en_US', PaypalBLocale::paypalLocale('en'));
        $this->assertSame('ja_JP', PaypalBLocale::paypalLocale('ja'));
        $this->assertSame('en_US', PaypalBLocale::paypalLocale(''));
        $this->assertSame('zh-CN', PaypalBLocale::htmlLang('zh_cn'));
        $this->assertSame('en', PaypalBLocale::htmlLang('en'));
    }

    public function test_paypal_b_local_payment_keeps_paypal_calls_outside_database_transactions(): void
    {
        $localPayment = file_get_contents('plugins/PaypalB/Services/PaypalBLocalPaymentService.php');
        $service      = file_get_contents('plugins/PaypalB/Services/PaypalBTransactionService.php');
        $migration    = file_get_contents('plugins/PaypalB/Migrations/2026_09_09_000015_relax_shop_order_id_unique_on_paypal_b_transactions.php');

        $this->assertStringContainsString("'paypal_b:create:local_order:'", $localPayment);
        $this->assertStringContainsString('$service->retryLocal($active)', $localPayment);
        $this->assertStringContainsString("trans('PaypalB::common.session_reconciling')", $localPayment);

        // 数据库事务闭包内只做状态校验与会话复用，PayPal 网络请求必须在闭包之外。
        $reserve    = substr($localPayment, strpos($localPayment, 'private function reserveTransactionLocked('));
        $closureEnd = strpos($reserve, "return ['order' => \$order, 'active' => \$active];");
        $this->assertNotFalse($closureEnd);
        $this->assertStringNotContainsString('createLocal(', substr($reserve, 0, $closureEnd));
        $this->assertStringNotContainsString('retryLocal(', substr($reserve, 0, $closureEnd));

        $this->assertStringContainsString('public function retryLocal(', $service);
        $this->assertStringContainsString('private function assertNoConflictingLocalAttempts(', $service);
        $this->assertSame(1, substr_count($service, 'private function submitPaypalOrder('));
        $this->assertSame(3, substr_count($service, '$this->submitPaypalOrder('));
        // 同一本站订单会随过期/重试产生多条 local 交易，shop_order_id 不能再是唯一索引。
        $this->assertStringContainsString('dropUnique(self::UNIQUE_INDEX)', $migration);
        $this->assertStringContainsString("'paypal_b_transactions_shop_order_id_index'", $migration);
    }

    public function test_paypal_plugins_only_catch_fully_qualified_throwables(): void
    {
        $files = [];
        foreach (['plugins/PaypalA', 'plugins/PaypalB'] as $root) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        $this->assertNotEmpty($files);
        foreach ($files as $path) {
            // 插件命名空间下不存在 Throwable 类，未加反斜杠的 catch (Throwable) 永远匹配不到任何异常。
            $this->assertSame(0, preg_match('/catch\s*\(\s*Throwable\b/', file_get_contents($path)), $path);
        }
    }

    public function test_paypal_b_bridge_endpoints_share_signature_verification_and_error_mapping(): void
    {
        $controller = file_get_contents('plugins/PaypalB/Controllers/PaypalBController.php');

        $this->assertSame(1, substr_count($controller, 'private function bridgeResponse('));
        $this->assertSame(3, substr_count($controller, '$this->bridgeResponse('));
        $this->assertSame(1, substr_count($controller, 'BridgeSignature::verify('));
        $this->assertSame(1, substr_count($controller, '$this->assertBridgeEnabled($configuration);'));
        $this->assertStringContainsString("'paypal_b:a_request'", $controller);
        $this->assertStringContainsString("'paypal_b:a_status'", $controller);
        $this->assertStringContainsString("'paypal_b:a_fulfillment'", $controller);
    }

    public function test_paypal_b_webhook_registrar_reuses_matching_webhooks_and_fills_missing_events(): void
    {
        $this->assertSame(
            'https://pay.example.test/api/paypal/webhooks/7',
            PaypalBWebhookRegistrar::webhookUrl('https://pay.example.test/', 7)
        );
        $accepted = PaypalBWebhookRegistrar::acceptedUrls('https://pay.example.test', 7);
        $this->assertContains('https://pay.example.test/api/paypal-b/webhooks/7', $accepted);

        $webhooks = [
            ['id' => 'WH-OTHER', 'url' => 'https://pay.example.test/api/paypal/webhooks/8', 'event_types' => [['name' => 'PAYMENT.CAPTURE.COMPLETED']]],
            ['id' => 'WH-LEGACY', 'url' => 'https://PAY.example.test/api/paypal-b/webhooks/7/', 'event_types' => [['name' => 'PAYMENT.CAPTURE.COMPLETED']]],
        ];
        $existing = PaypalBWebhookRegistrar::findExisting($webhooks, $accepted);
        $this->assertSame('WH-LEGACY', $existing['id']);
        $this->assertSame(
            ['PAYMENT.CAPTURE.REFUNDED'],
            PaypalBWebhookRegistrar::missingEventTypes($existing, ['PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.REFUNDED'])
        );
        $this->assertNull(PaypalBWebhookRegistrar::findExisting($webhooks, PaypalBWebhookRegistrar::acceptedUrls('https://pay.example.test', 9)));
        $this->assertSame(
            ['PAYMENT.CAPTURE.COMPLETED'],
            PaypalBWebhookRegistrar::availableEventTypes(['event_types' => [['name' => 'PAYMENT.CAPTURE.COMPLETED'], ['name' => ''], 'junk']])
        );
        $this->assertSame(['PAYMENT.CAPTURE.COMPLETED'], PaypalBWebhookRegistrar::REQUIRED_EVENT_TYPES);
        $this->assertSame([], array_diff(PaypalBWebhookRegistrar::REQUIRED_EVENT_TYPES, PaypalBWebhookRegistrar::EVENT_TYPES));
    }

    public function test_paypal_b_accounts_can_register_webhooks_automatically(): void
    {
        $routes     = file_get_contents('plugins/PaypalB/Routes/admin.php');
        $controller = file_get_contents('plugins/PaypalB/Controllers/PaypalBAdminController.php');
        $list       = file_get_contents('plugins/PaypalB/Views/admin/accounts.blade.php');
        $fields     = file_get_contents('plugins/PaypalB/Views/admin/account_fields.blade.php');
        $client     = file_get_contents('plugins/PaypalB/Services/PaypalBApiClient.php');
        $registrar  = file_get_contents('plugins/PaypalB/Services/PaypalBWebhookRegistrar.php');
        $bootstrap  = file_get_contents('plugins/PaypalB/Bootstrap.php');

        $this->assertStringContainsString("->name('accounts.register_webhook')", $routes);
        $this->assertStringContainsString('public function registerWebhook(PaypalBAccount $account)', $controller);
        $this->assertStringContainsString("\$request->boolean('register_webhook')", $controller);
        $this->assertStringContainsString("admin_route('paypal_b.accounts.register_webhook'", $list);
        $this->assertStringContainsString('name="register_webhook"', $fields);
        $this->assertStringContainsString("'/v1/notifications/webhooks'", $client);
        $this->assertStringContainsString("'/v1/notifications/webhooks-event-types'", $client);
        $this->assertStringContainsString("PaypalBWebhookRegistrar::fromSetting(plugin_setting('paypal_b', []))", $bootstrap);
        // PayPal 只接受 HTTPS 地址，必须在调用前拦截；已存在的同地址 Webhook 复用而不是重复创建。
        $this->assertStringContainsString("!== 'https'", $registrar);
        $this->assertStringContainsString('self::findExisting(', $registrar);
        $this->assertStringContainsString('replaceWebhookEventTypes(', $registrar);
    }

    public function test_paypal_b_local_payment_only_requires_the_primary_wallet_capability(): void
    {
        $service      = file_get_contents('plugins/PaypalB/Services/PaypalBTransactionService.php');
        $localPayment = file_get_contents('plugins/PaypalB/Services/PaypalBLocalPaymentService.php');
        $template     = file_get_contents('plugins/PaypalB/Views/checkout/payment.blade.php');

        // 信用卡是可选能力：开启信用卡开关后，仅支持钱包的账号仍必须能为本地订单收款。
        $this->assertStringContainsString("in_array('wallet', \$enabledMethods, true) ? 'wallet' : 'card'", $service);
        $this->assertStringContainsString('$this->selectAccount($currency, [$primaryMethod])', $service);
        $this->assertStringContainsString("'card_checkout_url'", $localPayment);
        $this->assertStringContainsString("\$paypal_b_instruction['wallet_enabled']", $template);
        $this->assertStringContainsString("\$paypal_b_instruction['card_checkout_url']", $template);
    }

    public function test_paypal_b_account_selector_explains_why_no_account_is_eligible(): void
    {
        $walletOnly = new PaypalBAccount([
            'name'                 => 'wallet-only',
            'account_type'         => PaypalBAccount::TYPE_BUSINESS_API,
            'supported_currencies' => 'USD',
            'wallet_enabled'       => true,
            'card_enabled'         => false,
        ]);
        $walletOnly->forceFill(['id' => 1]);
        $euroOnly = new PaypalBAccount([
            'name'                 => 'euro-only',
            'account_type'         => PaypalBAccount::TYPE_BUSINESS_API,
            'supported_currencies' => 'EUR',
            'wallet_enabled'       => true,
            'card_enabled'         => true,
        ]);
        $euroOnly->forceFill(['id' => 2]);

        $reasons = PaypalBAccountSelector::unavailabilityReasons([$walletOnly, $euroOnly], 'USD', ['card']);
        $this->assertCount(2, $reasons);
        $this->assertStringContainsString('信用卡', $reasons[0]);
        $this->assertStringContainsString('USD', $reasons[1]);
        $this->assertSame([], PaypalBAccountSelector::unavailabilityReasons([$walletOnly], 'USD', ['wallet']));
        $this->assertStringContainsString('没有已启用', PaypalBAccountSelector::unavailabilityReasons([], 'USD', ['wallet'])[0]);
    }

    public function test_native_payload_mode_submits_only_order_number_amount_and_product_names(): void
    {
        $data = [
            'reference'    => 'REF-NATIVE-1',
            'order_number' => 'ORDER-2001',
            'amount'       => '23.00',
            'currency'     => 'USD',
            'order_items'  => [[
                'name'       => 'Basketball Shorts',
                'sku'        => 'SKU-2001',
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
            'shipping_address' => ['required' => true, 'name' => 'Buyer', 'address_1' => '1 Main St', 'city' => 'SJ', 'country_code' => 'US'],
        ];
        $payload = PaypalBOrderPayload::buildFromData(
            $data,
            'https://pay.example.test/return',
            'https://pay.example.test/cancel',
            'B Merchant LLC',
            ['mode' => PaypalBOrderPayload::MODE_NATIVE]
        );

        $this->assertSame(['intent', 'purchase_units', 'application_context'], array_keys($payload));
        $unit = $payload['purchase_units'][0];
        // 只有订单号、商品名称和金额；实物订单也不带收货地址与商品行。
        $this->assertSame(['invoice_id', 'custom_id', 'description', 'amount'], array_keys($unit));
        $this->assertSame('ORDER-2001', $unit['invoice_id']);
        $this->assertSame('ORDER-2001', $unit['custom_id']);
        $this->assertSame('Basketball Shorts', $unit['description']);
        $this->assertSame(['currency_code' => 'USD', 'value' => '23.00'], $unit['amount']);
        // 不提交商城地址，实物订单让买家在 PayPal 自选；也不提交收款主体名称。
        $this->assertSame(['shipping_preference', 'return_url', 'cancel_url'], array_keys($payload['application_context']));
        $this->assertSame('GET_FROM_FILE', $payload['application_context']['shipping_preference']);
        $virtual = PaypalBOrderPayload::buildFromData(['shipping_address' => ['required' => false]] + $data, 'https://r', 'https://c', 'B', ['mode' => 'native']);
        $this->assertSame('NO_SHIPPING', $virtual['application_context']['shipping_preference']);

        // 默认仍是完整明细，且兼容模式同样不接受与明细不一致的总额。
        $detailed = PaypalBOrderPayload::buildFromData($data, 'https://r', 'https://c', 'B Merchant LLC');
        $this->assertSame('REF-NATIVE-1', $detailed['purchase_units'][0]['invoice_id']);
        $this->assertArrayHasKey('breakdown', $detailed['purchase_units'][0]['amount']);
        $this->assertSame('SET_PROVIDED_ADDRESS', $detailed['application_context']['shipping_preference']);
        $rejected = false;

        try {
            PaypalBOrderPayload::buildFromData(['amount' => '99.00'] + $data, 'https://r', 'https://c', 'B', ['mode' => 'native']);
        } catch (\Throwable) {
            $rejected = true;
        }
        $this->assertTrue($rejected, '兼容模式也必须拒绝与订单明细不一致的总额。');
    }

    public function test_native_payload_mode_is_configured_per_session_and_tolerated_by_webhooks(): void
    {
        $base = [
            'public_url'               => 'https://pay.example.test',
            'request_timeout_seconds'  => 10,
            'account_cooldown_minutes' => 10,
            'merchant_display_name'    => 'B Merchant LLC',
            'customer_service_email'   => 'support@pay.example.test',
            'refund_policy_url'        => 'https://pay.example.test/refunds',
            'privacy_policy_url'       => 'https://pay.example.test/privacy',
            'terms_url'                => 'https://pay.example.test/terms',
            'bridge_enabled'           => false,
        ];
        $this->assertSame('detailed', PaypalBConfiguration::from($base)['paypal_payload_mode']);
        $this->assertSame('native', PaypalBConfiguration::from($base + ['native_payload_mode' => '1'])['paypal_payload_mode']);

        // 会话上记录的格式优先于当前配置，未记录时才回退到配置，非法值回退到完整明细。
        $stored = new \Plugin\PaypalB\Models\PaypalBTransaction;
        $stored->forceFill(['bridge_request' => ['paypal_payload_mode' => 'native']]);
        $this->assertSame('native', PaypalBOrderPayload::modeFor($stored, ['paypal_payload_mode' => 'detailed']));
        $fresh = new \Plugin\PaypalB\Models\PaypalBTransaction;
        $fresh->forceFill(['bridge_request' => []]);
        $this->assertSame('native', PaypalBOrderPayload::modeFor($fresh, ['paypal_payload_mode' => 'native']));
        $this->assertSame('detailed', PaypalBOrderPayload::modeFor($fresh, ['paypal_payload_mode' => 'bogus']));

        $service = file_get_contents('plugins/PaypalB/Services/PaypalBTransactionService.php');
        $refunds = $this->normalizeSourceLineEndings(file_get_contents('plugins/PaypalB/Services/PaypalBRefundService.php'));
        $client  = $this->normalizeSourceLineEndings(file_get_contents('plugins/PaypalB/Services/PaypalBApiClient.php'));
        $columns = file_get_contents('plugins/PaypalB/columns.php');
        $form    = file_get_contents('plugins/PaypalB/Views/admin/config_form.blade.php');

        $this->assertSame(2, substr_count($service, "'paypal_payload_mode' => \$this->payloadMode()"));
        $this->assertStringContainsString("['mode' => PaypalBOrderPayload::modeFor(\$transaction, \$this->configuration)]", $service);
        // 发票编号在完整明细模式是支付引用、兼容模式是订单号：Webhook 只按 PayPal 订单号定位，发票编号携带时必须与其一一致。
        $this->assertStringContainsString("\$invoiceId !== '' && ! in_array(\$invoiceId, [(string) \$transaction->reference, (string) \$transaction->order_number], true)", $service);
        $this->assertStringNotContainsString("\$query->where('reference', \$invoiceId)", $service);
        $this->assertStringContainsString("? (string) \$transaction->order_number\n                : (string) \$transaction->reference;", $refunds);
        $this->assertStringContainsString("if (trim(\$reference) !== '') {\n            \$payload['invoice_id'] = trim(\$reference);", $client);
        $this->assertStringContainsString("'native_payload_mode'", $columns);
        $this->assertStringContainsString('name="native_payload_mode"', $form);
    }

    public function test_paypal_chosen_shipping_address_is_extracted_and_compared_with_the_shop_order(): void
    {
        $provider = ['purchase_units' => [[
            'shipping' => [
                'name'    => ['full_name' => 'John Doe'],
                'address' => [
                    'address_line_1' => '3483 Canyon Creek Dr',
                    'admin_area_2'   => 'San Jose',
                    'admin_area_1'   => 'CA',
                    'postal_code'    => '95132',
                    'country_code'   => 'US',
                ],
            ],
        ]]];
        $paypal = \Plugin\PaypalB\Models\PaypalBTransaction::shippingAddressFromProvider($provider);
        $this->assertSame('3483 Canyon Creek Dr', $paypal['address_1']);
        $this->assertSame('US', $paypal['country_code']);
        $this->assertNull(\Plugin\PaypalB\Models\PaypalBTransaction::shippingAddressFromProvider(['purchase_units' => [['amount' => []]]]));

        // 街道、邮编、国家一致即视为同一地址，城市和州的写法差异不算不一致。
        $this->assertTrue(\Plugin\PaypalB\Models\PaypalBTransaction::shippingAddressesMatch($paypal, [
            'address_1' => '3483 canyon creek dr.', 'postal_code' => '95132', 'country_code' => 'us', 'city' => 'SAN JOSE', 'zone' => 'California',
        ]));
        $this->assertFalse(\Plugin\PaypalB\Models\PaypalBTransaction::shippingAddressesMatch($paypal, [
            'address_1' => 'Twin Peaks Blvd', 'postal_code' => '94131', 'country_code' => 'CA',
        ]));

        $localOrder  = file_get_contents('plugins/PaypalB/Services/PaypalBLocalOrderService.php');
        $mirrorOrder = file_get_contents('plugins/PaypalB/Services/PaypalBShopOrderService.php');
        $list        = file_get_contents('plugins/PaypalB/Views/admin/accounts.blade.php');
        $this->assertStringContainsString("\$transaction->paidComment('PayPal 已确认收款。')", $localOrder);
        $this->assertStringContainsString('$transaction->paidComment(sprintf(', $mirrorOrder);
        $this->assertStringContainsString('paypalShippingMatchesOrder() === false', $list);
    }

    public function test_pending_captures_are_recorded_never_expire_and_complete_later(): void
    {
        $transaction = new \Plugin\PaypalB\Models\PaypalBTransaction;
        $transaction->forceFill(['status' => 'pending', 'paypal_capture_id' => 'CAP-1']);
        $this->assertTrue($transaction->hasPendingCapture());
        $transaction->forceFill(['status' => 'completed']);
        $this->assertFalse($transaction->hasPendingCapture());
        $transaction->forceFill(['status' => 'pending', 'paypal_capture_id' => null]);
        $this->assertFalse($transaction->hasPendingCapture());

        $service = $this->normalizeSourceLineEndings(file_get_contents('plugins/PaypalB/Services/PaypalBTransactionService.php'));
        // 捕获返回 PENDING 时记录捕获号，之后不再重复捕获，只查询原订单的最新结果。
        $this->assertStringContainsString('private function markCapturePending(', $service);
        $this->assertStringContainsString("'payment_exception_status'  => 'capture_pending'", $service);
        $this->assertStringContainsString('if ($transaction->hasPendingCapture()) {' . "\n" . '            // 已经捕获过', $service);
        $this->assertStringContainsString('return $this->refreshCapture($transaction, $account, $client);', $service);
        // 有捕获号的会话不会被状态查询或结账页判为过期。
        $statusPayload = substr($service, strpos($service, 'public function statusPayload('), 600);
        $this->assertStringContainsString('&& ! $transaction->hasPendingCapture()', $statusPayload);
        $assertActive = substr($service, strpos($service, 'private function assertActive('), 700);
        $this->assertStringContainsString('if ($transaction->hasPendingCapture()) {', $assertActive);
        // PayPal 几天后才完成时按捕获号入账，不受会话有效期限制；没有捕获号的过期会话仍被拒绝。
        $markCompleted = substr($service, strpos($service, 'private function markCompleted('));
        $this->assertStringContainsString('$completingRecordedCapture = $locked->paypal_capture_id', $markCompleted);
        $this->assertStringContainsString('if (! $completingRecordedCapture', $markCompleted);
        $this->assertStringContainsString('支付会话已过期，不能再确认收款。', $markCompleted);
        // 拒绝的捕获不保留捕获号，否则该订单永远不能重新付款。
        $denied = substr($service, strpos($service, 'private function markCaptureDenied('), 900);
        $this->assertStringContainsString("'paypal_capture_id'         => null", $denied);
        $this->assertStringContainsString("'payment_exception_status'  => 'capture_denied'", $denied);
        // 待确认状态也会回调 A 站，并订阅 PENDING/DENIED 事件。
        $this->assertStringContainsString("['PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.PENDING', 'PAYMENT.CAPTURE.DENIED']", $service);
        $this->assertContains('PAYMENT.CAPTURE.PENDING', PaypalBWebhookRegistrar::EVENT_TYPES);
        $this->assertContains('PAYMENT.CAPTURE.DENIED', PaypalBWebhookRegistrar::EVENT_TYPES);
        $callbacks = file_get_contents('plugins/PaypalB/Services/PaypalBCallbackService.php');
        $this->assertStringContainsString('&& ! $transaction->hasPendingCapture()', $callbacks);
        $dispatch = file_get_contents('plugins/PaypalB/Console/DispatchPaypalBCallbacks.php');
        $this->assertStringContainsString("->where('status', 'pending')->whereNotNull('paypal_capture_id')", $dispatch);
    }

    public function test_buyers_are_told_not_to_pay_again_while_paypal_confirms_the_capture(): void
    {
        $bController  = $this->normalizeSourceLineEndings(file_get_contents('plugins/PaypalB/Controllers/PaypalBController.php'));
        $bModel       = $this->normalizeSourceLineEndings(file_get_contents('plugins/PaypalB/Models/PaypalBTransaction.php'));
        $bBootstrap   = $this->normalizeSourceLineEndings(file_get_contents('plugins/PaypalB/Bootstrap.php'));
        $localPayment = $this->normalizeSourceLineEndings(file_get_contents('plugins/PaypalB/Services/PaypalBLocalPaymentService.php'));
        $partial      = $this->normalizeSourceLineEndings(file_get_contents('plugins/PaypalB/Views/checkout/partials/wallet_buttons.blade.php'));
        $card         = $this->normalizeSourceLineEndings(file_get_contents('plugins/PaypalB/Views/checkout/card.blade.php'));
        $aController  = $this->normalizeSourceLineEndings(file_get_contents('plugins/PaypalA/Controllers/PaypalAController.php'));
        $aService     = $this->normalizeSourceLineEndings(file_get_contents('plugins/PaypalA/Services/PaypalAPaymentService.php'));
        $aTemplate    = $this->normalizeSourceLineEndings(file_get_contents('plugins/PaypalA/Views/checkout/payment.blade.php'));

        // B 站结账页与本地支付页：已捕获待确认时不再渲染按钮。
        $this->assertStringContainsString("trans('PaypalB::common.session_capture_pending')", $bController);
        $this->assertStringContainsString("->where('status', 'pending')\n            ->whereNotNull('paypal_capture_id')", $bModel);
        $this->assertStringContainsString('PaypalBTransaction::hasLocalPendingCaptureForOrder((int) $order->id)', $localPayment);
        $this->assertStringContainsString("->whereNull('paypal_capture_id')\n                ->where('expires_at', '<=', now())", $localPayment);
        // 轮询超时后送回原站点，不把买家留在支付页。
        $this->assertStringContainsString('setTimeout(() => window.location.assign(returnUrl), 3000);', $partial);
        $this->assertStringContainsString('setTimeout(() => window.location.assign(returnUrl), 3000);', $card);
        // 本站订单已捕获待确认时落到成功页并说明审核中，而不是回到没有订单入口的支付页。
        $this->assertStringContainsString("\$transaction->status === 'completed' || \$transaction->hasPendingCapture()\n            ? shop_route('checkout.success'", $bController);
        $this->assertStringContainsString("add_hook_blade('checkout.success.checkout_success_title.after'", $bBootstrap);
        $this->assertStringContainsString("view('PaypalB::checkout.partials.review_notice')", $bBootstrap);
        $this->assertFileExists('plugins/PaypalB/Views/checkout/partials/review_notice.blade.php');
        // A 站：不再开新会话、不再跳转，支付页只显示等待提示。
        $this->assertStringContainsString('PaypalAPaymentService::hasPendingCapture($awaiting)', $aController);
        $this->assertStringContainsString("trans('PaypalA::common.status_capture_pending')", $aController);
        $this->assertStringContainsString('public static function hasPendingCapture(PaypalATransaction $transaction): bool', $aService);
        $this->assertStringContainsString("'capture_pending' => self::orderHasPendingCapture(\$this->order)", $aService);
        $this->assertStringContainsString("\$paypal_a_instruction['capture_pending']", $aTemplate);
        $this->assertStringContainsString("trans('PaypalA::common.capture_pending_notice')", $aTemplate);
        foreach (['en', 'zh_cn', 'zh_hk'] as $locale) {
            $this->assertStringContainsString("'status_capture_pending'", file_get_contents("plugins/PaypalA/Lang/{$locale}/common.php"));
            $this->assertStringContainsString("'session_capture_pending'", file_get_contents("plugins/PaypalB/Lang/{$locale}/common.php"));
            $this->assertStringContainsString("'success_review_notice'", file_get_contents("plugins/PaypalB/Lang/{$locale}/common.php"));
        }
    }

    public function test_pending_captures_can_be_reconciled_from_admin_and_schedule(): void
    {
        $bootstrap = file_get_contents('plugins/PaypalB/Bootstrap.php');
        $command   = file_get_contents('plugins/PaypalB/Console/SyncPaypalBPendingCaptures.php');
        $kernel    = file_get_contents('app/Console/Kernel.php');
        $routes    = file_get_contents('plugins/PaypalB/Routes/admin.php');
        $admin     = file_get_contents('plugins/PaypalB/Controllers/PaypalBAdminController.php');
        $list      = file_get_contents('plugins/PaypalB/Views/admin/accounts.blade.php');
        $service   = file_get_contents('plugins/PaypalB/Services/PaypalBTransactionService.php');

        $this->assertStringContainsString('SyncPaypalBPendingCaptures::class', $bootstrap);
        $this->assertStringContainsString("'paypal-b:sync-pending-captures", $command);
        $this->assertStringContainsString("->whereNotNull('paypal_capture_id')", $command);
        $this->assertStringContainsString("\$schedule->command('paypal-b:sync-pending-captures')", $kernel);
        $this->assertLessThan(strpos($kernel, 'paypal-b:sync-pending-captures'), strpos($kernel, 'if ($paypalB && $paypalB->getEnabled())'));
        $this->assertStringContainsString("->name('transactions.sync')", $routes);
        $this->assertStringContainsString('public function syncPaypalStatus(PaypalBTransaction $transaction)', $admin);
        $this->assertStringContainsString('public function syncWithPaypal(PaypalBTransaction $transaction)', $service);
        $this->assertStringContainsString("admin_route('paypal_b.transactions.sync'", $list);
        $this->assertStringContainsString('$transaction->pendingReason()', $list);
        $this->assertStringContainsString('$transaction->sellerProtectionStatus()', $list);
    }

    public function test_paypal_b_purge_command_deletes_mirror_orders_before_sessions_and_spares_local_orders(): void
    {
        $command   = file_get_contents('plugins/PaypalB/Console/PurgePaypalBData.php');
        $bootstrap = file_get_contents('plugins/PaypalB/Bootstrap.php');
        $readme    = file_get_contents('plugins/PaypalB/README.md');

        $this->assertStringContainsString('PurgePaypalBData::class', $bootstrap);
        $this->assertStringContainsString("'paypal-b:purge", $command);
        $this->assertStringContainsString('{--dry-run', $command);
        $this->assertStringContainsString("app()->environment('production') && ! \$this->option('force')", $command);
        // 镜像订单只能通过交易表关联找到，删除必须排在交易表之前；本站真实订单永远不在删除范围内。
        $this->assertStringContainsString("->where('source', '<>', PaypalBTransaction::SOURCE_LOCAL)", $command);
        $this->assertLessThan(
            strpos($command, "DB::table('paypal_b_transactions')->delete();"),
            strpos($command, '$this->deleteMirrorOrders($mirrorOrderIds);')
        );
        $this->assertStringContainsString("DB::table('paypal_b_rotation_states')->update(['last_account_id' => null", $command);
        $this->assertStringNotContainsString("DB::table('paypal_b_rotation_states')->delete()", $command);
        $this->assertStringContainsString('paypal-b:purge', $readme);
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

    public function test_embedded_checkout_is_opt_in_on_both_sites_and_falls_back_to_redirect(): void
    {
        $bConfiguration = PaypalBConfiguration::from([
            'bridge_enabled'                => true,
            'public_url'                    => 'https://pay.example.test',
            'a_site_url'                    => 'https://shop.example.test:8443',
            'a_request_verification_secret' => str_repeat('a', 32),
            'a_callback_signing_secret'     => str_repeat('b', 32),
            'request_timeout_seconds'       => 10,
            'account_cooldown_minutes'      => 10,
            'merchant_display_name'         => 'B Merchant LLC',
            'customer_service_email'        => 'support@pay.example.test',
        ]);
        // 默认不允许内嵌；开启后 frame-ancestors 只放开到 A 站 origin（含非默认端口）。
        $this->assertFalse($bConfiguration['embedded_enabled']);
        $this->assertSame('', $bConfiguration['a_site_origin']);
        $this->assertSame('on_completed', $bConfiguration['mirror_order_timing']);
        $this->assertFalse($bConfiguration['mirror_order_deduct_stock']);
        $enabled = PaypalBConfiguration::from([
            'bridge_enabled'                => true,
            'embedded_enabled'              => '1',
            'mirror_order_timing'           => 'on_session',
            'public_url'                    => 'https://pay.example.test',
            'a_site_url'                    => 'https://shop.example.test:8443',
            'a_request_verification_secret' => str_repeat('a', 32),
            'a_callback_signing_secret'     => str_repeat('b', 32),
            'request_timeout_seconds'       => 10,
            'account_cooldown_minutes'      => 10,
            'merchant_display_name'         => 'B Merchant LLC',
            'customer_service_email'        => 'support@pay.example.test',
        ]);
        $this->assertTrue($enabled['embedded_enabled']);
        $this->assertSame('https://shop.example.test:8443', $enabled['a_site_origin']);
        $this->assertSame('on_session', $enabled['mirror_order_timing']);
        $this->assertSame('https://shop.example.test', PaypalBConfiguration::origin('https://shop.example.test:443/'));

        // 未开启桥接时即使勾选内嵌也无效：没有可信的父页面 origin。
        $local = PaypalBConfiguration::from([
            'bridge_enabled'         => false,
            'embedded_enabled'       => '1',
            'public_url'             => 'https://pay.example.test',
            'request_timeout_seconds' => 10,
            'account_cooldown_minutes' => 10,
            'merchant_display_name'  => 'B Merchant LLC',
            'customer_service_email' => 'support@pay.example.test',
        ]);
        $this->assertFalse($local['embedded_enabled']);

        // B 站未允许时以稳定错误码拒绝 embedded；A 站按同一常量降级。
        // 纯单元环境没有容器，无法构造 ValidationException；拒绝分支已由带容器的脚本验证，这里只验证常量与放行路径。
        $service   = new PaypalBTransactionService($bConfiguration);
        $validator = new \ReflectionMethod($service, 'validatePresentation');
        $validator->setAccessible(true);
        $this->assertSame('redirect', $validator->invoke($service, null));
        $this->assertSame('redirect', $validator->invoke($service, 'REDIRECT'));
        $this->assertSame('embedded', $validator->invoke(new PaypalBTransactionService($enabled), 'embedded'));
        $this->assertSame('embedded_not_allowed', PaypalBTransactionService::EMBEDDED_NOT_ALLOWED);
        $this->assertSame(PaypalBTransactionService::EMBEDDED_NOT_ALLOWED, \Plugin\PaypalA\Services\PaypalABridgeClient::EMBEDDED_NOT_ALLOWED);
        $txService = file_get_contents('plugins/PaypalB/Services/PaypalBTransactionService.php');
        $this->assertStringContainsString("if (\$presentation === PaypalBTransaction::PRESENTATION_EMBEDDED && empty(\$this->configuration['embedded_enabled'])) {", $txService);

        // A 站：默认跳转；内嵌参数有范围；降级默认开启。
        $aSetting = [
            'public_url'                   => 'https://shop.example.test',
            'b_site_url'                   => 'https://pay.example.test',
            'request_signing_secret'       => str_repeat('a', 32),
            'callback_verification_secret' => str_repeat('b', 32),
            'payment_expiration_minutes'   => 30,
            'request_timeout_seconds'      => 10,
        ];
        $aConfiguration = PaypalAPaymentService::bridgeConfiguration($aSetting);
        $this->assertSame('redirect', $aConfiguration['checkout_mode']);
        $this->assertTrue($aConfiguration['embedded_fallback']);
        $this->assertSame(8, $aConfiguration['embedded_ready_timeout_seconds']);
        $this->assertSame(3, $aConfiguration['embedded_status_poll_seconds']);
        $this->assertSame('https://pay.example.test', $aConfiguration['b_site_origin']);
        $this->assertSame('embedded', PaypalAPaymentService::bridgeConfiguration($aSetting + ['checkout_mode' => 'embedded'])['checkout_mode']);
        $this->assertFalse(PaypalAPaymentService::bridgeConfiguration($aSetting + ['embedded_fallback' => '0'])['embedded_fallback']);
        $aService = file_get_contents('plugins/PaypalA/Services/PaypalAPaymentService.php');
        $this->assertStringContainsString('if ($readyTimeout < 5 || $readyTimeout > 20 || $statusPoll < 2 || $statusPoll > 10) {', $aService);
    }

    public function test_embedded_pages_notify_the_parent_and_a_site_degrades_on_failure(): void
    {
        $aController = file_get_contents('plugins/PaypalA/Controllers/PaypalAController.php');
        $aTemplate   = file_get_contents('plugins/PaypalA/Views/checkout/embedded.blade.php');
        $aPayment    = file_get_contents('plugins/PaypalA/Views/checkout/payment.blade.php');
        $bController = file_get_contents('plugins/PaypalB/Controllers/PaypalBController.php');
        $bridge      = file_get_contents('plugins/PaypalB/Views/checkout/partials/embed_bridge.blade.php');
        $finish      = file_get_contents('plugins/PaypalB/Views/checkout/embedded_finish.blade.php');
        $layout      = file_get_contents('plugins/PaypalB/Views/layout/checkout.blade.php');
        $partial     = file_get_contents('plugins/PaypalB/Views/checkout/partials/wallet_buttons.blade.php');
        $card        = file_get_contents('plugins/PaypalB/Views/checkout/card.blade.php');
        $error       = file_get_contents('plugins/PaypalB/Views/checkout/error.blade.php');

        // A 站支付页与原生 Paypal 插件一样只放一个按钮容器；跳转模式的表单原样保留。
        $this->assertStringContainsString("=== 'embedded')", $aPayment);
        $this->assertStringContainsString("@include('PaypalA::checkout.embedded')", $aPayment);
        $this->assertStringContainsString('<form method="POST" action="{{ $paypal_a_instruction[\'start_url\'] }}" rel="noreferrer">', $aPayment);
        $this->assertStringContainsString('id="paypal-button-container"', $aTemplate);
        $this->assertStringNotContainsString('paypal.com/sdk/js', $aTemplate);

        // B 站只向配置的 A 站 origin 发消息，且消息不带金额；未内嵌时全部空操作。
        $this->assertStringContainsString("window.parent.postMessage({ source: 'paypal_b', event, reference, ...extra }, origin);", $bridge);
        $this->assertStringNotContainsString("'*'", $bridge);
        $this->assertStringContainsString('const active = embedded && origin !== \'\' && framed;', $bridge);
        $this->assertStringContainsString("@include('PaypalB::checkout.partials.embed_bridge')", $layout);
        foreach (['completed', 'pending_capture', 'load_failed', 'failed'] as $event) {
            $this->assertStringContainsString("notify('{$event}')", $partial);
            $this->assertStringContainsString("notify('{$event}')", $card);
        }
        $this->assertStringContainsString('target="_top"', $error);
        $this->assertStringContainsString('window.top.location.assign(returnUrl)', $finish);
        $this->assertStringContainsString("'PaypalB::checkout.embedded_finish'", $bController);
        // 内嵌会话的 finish 不再 302 进 iframe；redirect 会话保持 302。
        $this->assertStringContainsString('if ($this->embedOrigin($transaction) !== \'\') {', $bController);
        $this->assertStringContainsString('$response = redirect()->away($this->aReturnUrl($transaction));', $bController);

        // A 站降级：B 拒绝 → 同请求重建 redirect；旧版 B 不回显 → 视为不支持；关闭降级则报错。
        $this->assertStringContainsString("in_array(PaypalABridgeClient::EMBEDDED_NOT_ALLOWED, (array) (\$exception->errors()['presentation'] ?? []), true)", $aController);
        $this->assertStringContainsString("(\$response['presentation'] ?? null) !== PaypalAPaymentService::CHECKOUT_MODE_EMBEDDED", $aController);
        $this->assertStringContainsString("recordEmbeddedFallback(\$number, 'b_site_legacy')", $aController);
        $this->assertStringContainsString("trans('PaypalA::common.embedded_not_allowed')", $aController);
        $this->assertStringContainsString("degrade('ready_timeout')", $aTemplate);
        $this->assertStringContainsString("degrade('sdk_load_failed')", $aTemplate);
        $this->assertStringContainsString('if (config.fallback && state.checkoutUrl) {', $aTemplate);
        $this->assertStringContainsString("if (payload.presentation !== 'embedded') {", $aTemplate);
    }

    public function test_mirror_orders_can_be_created_at_session_time_with_catalog_products_and_price_adjustment(): void
    {
        $service   = $this->normalizeSourceLineEndings(file_get_contents('plugins/PaypalB/Services/PaypalBShopOrderService.php'));
        $txService = file_get_contents('plugins/PaypalB/Services/PaypalBTransactionService.php');
        $bootstrap = file_get_contents('plugins/PaypalB/Bootstrap.php');
        $model     = file_get_contents('plugins/PaypalB/Models/PaypalBTransaction.php');
        $backfill  = file_get_contents('plugins/PaypalB/Console/BackfillPaypalBShopOrders.php');
        $list      = file_get_contents('plugins/PaypalB/Views/admin/accounts.blade.php');

        // 建会话成功即建待支付镜像订单（仅 on_session）；收款后推进；终结时取消。
        $this->assertStringContainsString('app(PaypalBShopOrderService::class)->createPendingFromTransaction($transaction->fresh());', $txService);
        $this->assertStringContainsString("if (! \$this->createsOnSession()) {\n            return null;", $service);
        $this->assertStringContainsString("if (\$transaction->shop_order_id) {\n            \$this->markPaid(\$transaction);", $service);
        foreach (["'expired'", "'capture_denied'", '$reason'] as $reason) {
            $this->assertStringContainsString("cancelFromTransaction(\$transaction->fresh(), {$reason})", $txService);
        }
        $this->assertStringContainsString("cancelFromTransaction(\$transaction->fresh(), 'buyer_cancelled')", file_get_contents('plugins/PaypalB/Controllers/PaypalBController.php'));
        $this->assertStringContainsString("if (! \$order || \$order->status !== StateMachineService::UNPAID) {\n                    return;", $service);

        // 商品按目录分配，未命中打标；差价单独一行，总额等于实收；本站自营订单不受影响。
        $this->assertStringContainsString("public const ADJUSTMENT_CODE = 'paypal_b_adjustment';", $service);
        $this->assertStringContainsString('$adjustmentMinor = $amountMinor - $subMinor - $feeMinor;', $service);
        $this->assertStringContainsString("'value' => PaypalBMoney::normalize((string) \$transaction->amount, \$currency)", $service);
        $this->assertStringContainsString("public const RISK_FLAG_UNMAPPED = 'mirror_unmapped';", $service);
        $this->assertStringContainsString("'product_id' => 0,", $service);
        $this->assertStringContainsString('public static function isMirrorOrder(int $orderId): bool', $model);
        $this->assertStringContainsString('if (! PaypalBTransaction::isMirrorOrder((int) $order->id)) {', $bootstrap);
        $this->assertStringContainsString("\$removed = ['notifyNewOrder', 'notifyUpdateOrder', 'uniPushMessage', 'updateSales'];", $bootstrap);
        $this->assertStringContainsString("\$removed[] = 'subStock';", $bootstrap);
        $this->assertStringContainsString('--with-adjustment', $backfill);
        $this->assertStringContainsString('RISK_FLAG_UNMAPPED, (array) $transaction->risk_flags', $list);
    }

    private function normalizeSourceLineEndings(string $contents): string
    {
        return str_replace(["\r\n", "\r"], "\n", $contents);
    }
}
