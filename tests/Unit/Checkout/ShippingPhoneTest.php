<?php

namespace Tests\Unit\Checkout;

use Beike\Shop\Http\Requests\AddressRequest;
use Beike\Shop\Services\CheckoutService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use TorMorten\Eventy\Events;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ShippingPhoneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container;
        $container->instance('config', new Repository);
        $container->instance('eventy', new Events);
        $container->instance('translator', new Translator(new ArrayLoader, 'en'));
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public static function invalidPhones(): array
    {
        return [
            'missing' => [[]],
            'null'    => [['phone' => null]],
            'empty'   => [['phone' => '']],
            'spaces'  => [['phone' => '   ']],
            'array'   => [['phone' => ['12345678']]],
        ];
    }

    /** @dataProvider invalidPhones */
    public function test_shipping_address_save_rejects_empty_or_invalid_phone(array $address): void
    {
        $request = AddressRequest::create('/account/addresses', 'POST', $address + ['address_type' => 'shipping_address_id']);
        $rules   = $request->rules();
        $factory = new Factory(app('translator'));

        $this->assertTrue($factory->make($address, ['phone' => $rules['phone']])->fails());
    }

    /** @dataProvider invalidPhones */
    public function test_guest_shipping_update_rejects_invalid_phone_before_writing_cart(array $address): void
    {
        $checkout       = $this->checkout(true);
        $checkout->cart = Mockery::mock();
        $checkout->cart->shouldNotReceive('update');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('shop/checkout.enter_phone');

        (new ReflectionMethod(CheckoutService::class, 'updateGuestShippingAddress'))->invoke($checkout, $address);
    }

    public function test_billing_and_account_address_save_still_follow_setting(): void
    {
        foreach ([[], ['address_type' => 'payment_address_id']] as $data) {
            $request = AddressRequest::create('/account/addresses', 'POST', $data);
            config(['bk.system.base.address_phoner_equired' => '0']);
            $this->assertArrayNotHasKey('phone', $request->rules());

            config(['bk.system.base.address_phoner_equired' => '1']);
            $factory = new Factory(app('translator'));
            $this->assertTrue($factory->make([], ['phone' => $request->rules()['phone']])->fails());
        }
    }

    public function test_guest_order_confirmation_requires_phone_on_saved_shipping_address(): void
    {
        $checkout = $this->checkout(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('shop/checkout.enter_phone');

        $this->confirm($checkout, ['guest_shipping_address' => ['name' => 'Buyer', 'phone' => '']]);
    }

    public function test_customer_order_confirmation_requires_phone_on_legacy_shipping_address(): void
    {
        $customer = (object) ['id' => 7];
        $checkout = $this->checkout(true, $customer);
        $query    = Mockery::mock();
        $query->shouldReceive('find')->with(11)->once()->andReturn((object) ['customer_id' => 7, 'phone' => null]);
        Mockery::mock('alias:Beike\Models\Address')->shouldReceive('query')->once()->andReturn($query);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('shop/checkout.enter_phone');

        $this->confirm($checkout, ['shipping_address_id' => 11]);
    }

    public function test_physical_order_accepts_international_phone_and_optional_billing_phone(): void
    {
        $checkout = $this->checkout(true);
        $plugins  = Mockery::mock('alias:Beike\Repositories\PluginRepo');
        $plugins->shouldReceive('shippingEnabled')->with('shipping')->andReturn(true);
        $plugins->shouldReceive('paymentEnabled')->with('payment')->andReturn(true);

        foreach (['+1 (202) 555-0123', '+44 20 7946 0958', '13800138000'] as $phone) {
            $this->confirm($checkout, [
                'guest_shipping_address' => ['phone' => $phone],
                'guest_payment_address'  => ['name' => 'Buyer'],
                'shipping_method_code'   => 'shipping',
                'payment_method_code'    => 'payment',
            ]);
        }

        $this->addToAssertionCount(3);
    }

    public function test_virtual_order_does_not_require_shipping_phone(): void
    {
        $checkout = $this->checkout(false);
        Mockery::mock('alias:Beike\Repositories\PluginRepo')->shouldReceive('paymentEnabled')->with('payment')->once()->andReturn(true);

        $this->confirm($checkout, [
            'guest_shipping_address' => null,
            'guest_payment_address'  => ['name' => 'Buyer'],
            'payment_method_code'    => 'payment',
        ]);

        $this->addToAssertionCount(1);
    }

    private function checkout(bool $shipping, object $customer = null): CheckoutService
    {
        $auth = Mockery::mock();
        $auth->shouldReceive('guard')->andReturnSelf();
        $auth->shouldReceive('user')->andReturn($customer);
        app()->instance(\Illuminate\Contracts\Auth\Factory::class, $auth);
        Mockery::mock('alias:Beike\Repositories\CartRepo')->shouldReceive('shippingRequired')->andReturn($shipping);

        $checkout           = (new ReflectionClass(CheckoutService::class))->newInstanceWithoutConstructor();
        $checkout->customer = $customer;

        return $checkout;
    }

    private function confirm(CheckoutService $checkout, array $current): void
    {
        (new ReflectionMethod(CheckoutService::class, 'validateConfirm'))->invoke($checkout, ['current' => $current]);
    }
}
