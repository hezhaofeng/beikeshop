<?php

namespace Tests\Unit\OfflineTransfer;

use Beike\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OptionalTransferReceiptTest extends TestCase
{
    public static function transferPlugins(): array
    {
        return [
            'offline transfer' => ['OfflineTransfer', 'offline_transfer'],
            'western union'    => ['WesternUnion', 'western_union'],
        ];
    }

    /** @dataProvider transferPlugins */
    public function test_receipt_is_optional_even_with_legacy_required_setting(string $plugin, string $code): void
    {
        $serviceClass = "Plugin\\{$plugin}\\Services\\{$plugin}PaymentService";
        $service      = new $serviceClass($this->makeOrder($code));

        foreach ([[], ['receipt_required' => '1'], ['receipt_required' => '0']] as $setting) {
            $setting['transfer_instruction'] = 'Account: 123456';

            $this->assertFalse($service->instruction($setting)['receipt_required']);
            $this->assertFalse($service->mobilePaymentData($setting)['instruction']['receipt_required']);
        }
    }

    /** @dataProvider transferPlugins */
    public function test_transaction_id_without_receipt_creates_pending_declaration(string $plugin, string $code): void
    {
        config(["bk.plugin.{$code}.receipt_required" => '1']);
        $controllerClass = "Plugin\\{$plugin}\\Controllers\\{$plugin}Controller";
        $serviceClass    = "Plugin\\{$plugin}\\Services\\{$plugin}PaymentService";
        $controller      = new $controllerClass;
        $order           = $this->makeOrder($code);
        $data            = ['transaction_id' => ' TX-001 ', 'receipt' => null];

        $validation = new \ReflectionMethod($controller, 'validateDeclaration');
        $validation->invoke($controller, $data);
        $storage     = new \ReflectionMethod($controller, 'storeReceipt');
        $receiptPath = $storage->invoke($controller, Request::create('/declaration', 'POST', $data), $order);
        $payment     = (new $serviceClass($order))->customerDeclaration($data, $receiptPath);

        $this->assertNull($receiptPath);
        $this->assertArrayNotHasKey('receipt', $payment);
        $this->assertSame(['request'], array_keys($payment));
        $this->assertSame('TX-001', $payment['request']['transaction_id']);
        $this->assertSame('customer_declaration', $payment['request']['source']);
        $this->assertSame($code, $payment['request']['channel']);
        $this->assertSame('unpaid', $order->status);
    }

    /** @dataProvider transferPlugins */
    public function test_declaration_still_requires_transaction_id_or_receipt(string $plugin, string $code): void
    {
        $controllerClass = "Plugin\\{$plugin}\\Controllers\\{$plugin}Controller";
        $controller      = new $controllerClass;
        $validation      = new \ReflectionMethod($controller, 'validateDeclaration');

        try {
            $validation->invoke($controller, ['transaction_id' => '   ', 'receipt' => null]);
            $this->fail('缺少交易号和凭证的付款申报应被拒绝。');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('transaction_id', $exception->errors());
        }
    }

    /** @dataProvider transferPlugins */
    public function test_existing_receipt_allows_resubmission_without_new_upload(string $plugin, string $code): void
    {
        $controllerClass = "Plugin\\{$plugin}\\Controllers\\{$plugin}Controller";
        $controller      = new $controllerClass;
        $validation      = new \ReflectionMethod($controller, 'validateDeclaration');

        $this->assertNull($validation->invoke($controller, ['receipt' => null], true));
    }

    /** @dataProvider transferPlugins */
    public function test_uploaded_receipt_is_still_accepted_without_transaction_id(string $plugin, string $code): void
    {
        $controllerClass = "Plugin\\{$plugin}\\Controllers\\{$plugin}Controller";
        $controller      = new $controllerClass;
        $validation      = new \ReflectionMethod($controller, 'validateDeclaration');

        $this->assertNull($validation->invoke($controller, [
            'receipt' => UploadedFile::fake()->create('receipt.pdf', 1, 'application/pdf'),
        ]));
    }

    /** @dataProvider transferPlugins */
    public function test_checkout_receipt_input_is_optional(string $plugin, string $code): void
    {
        app('view')->addNamespace($plugin, base_path("plugins/{$plugin}/Views"));
        app('url')->resolveMissingNamedRoutesUsing(static function (string $name, array $parameters, bool $absolute): string {
            return url('/transfer-test/' . $name);
        });
        session(['guest_order_numbers' => []]);

        // 即使传入旧版支付参数，上传框也不能恢复为必填。
        $html = view("{$plugin}::checkout.payment", [
            'order'                => $this->makeOrder($code),
            $code . '_instruction' => [
                'transfer_instruction' => 'Account: 123456',
                'receipt_required'     => true,
                'amount'               => '18.70',
                'currency'             => 'EUR',
                'order_number'         => 'TRANSFER-1001',
            ],
        ])->render();

        $document = new \DOMDocument;
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath   = new \DOMXPath($document);
        $receipt = $xpath->query('//input[@name="receipt"]')->item(0);

        $this->assertInstanceOf(\DOMElement::class, $receipt);
        $this->assertFalse($receipt->hasAttribute('required'));
        $this->assertSame(1, $xpath->query('//input[@name="email" and @required]')->length);
    }

    /** @dataProvider transferPlugins */
    public function test_admin_settings_can_be_saved_without_receipt_requirement(string $plugin, string $code): void
    {
        app('view')->share('errors', new \Illuminate\Support\ViewErrorBag);
        app('view')->addNamespace($plugin, base_path("plugins/{$plugin}/Views"));
        $setting = [
            'transfer_instruction'           => 'Account: 123456',
            'quantity_restriction_enabled'   => '0',
            'quantity_restriction_threshold' => null,
            'status'                         => '1',
        ];
        $rules   = array_column(require base_path("plugins/{$plugin}/columns.php"), 'rules', 'name');

        $this->assertTrue(validator($setting, $rules)->passes());

        $pluginData = new class($code, $setting)
        {
            public function __construct(public string $code, private array $setting)
            {
            }

            public function getSetting(): array
            {
                return $this->setting + ['receipt_required' => '1'];
            }
        };
        $html = view("{$plugin}::admin.config_form", ['plugin' => $pluginData])->render();

        $this->assertStringContainsString('name="transfer_instruction"', $html);
        $this->assertStringNotContainsString('name="receipt_required"', $html);
    }

    private function makeOrder(string $code): Order
    {
        return new Order([
            'number'              => 'TRANSFER-1001',
            'customer_id'         => 0,
            'email'               => 'buyer@example.com',
            'total'               => 18.70,
            'currency_code'       => 'EUR',
            'status'              => 'unpaid',
            'payment_method_code' => $code,
        ]);
    }
}
