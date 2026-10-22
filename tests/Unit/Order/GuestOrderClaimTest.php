<?php

namespace Tests\Unit\Order;

use Beike\Models\Customer;
use Beike\Repositories\OrderRepo;
use Illuminate\Database\Eloquent\Builder;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class GuestOrderClaimTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_claim_only_updates_unowned_orders_matching_customer_email(): void
    {
        [$query, $unownedOrders] = $this->queryMocks();
        $query->shouldReceive('whereRaw')
            ->once()
            ->with('LOWER(TRIM(email)) = ?', ['buyer@example.com'])
            ->andReturnSelf();
        $query->shouldReceive('whereIn')->never();
        $query->shouldReceive('update')
            ->once()
            ->with(['customer_id' => 42])
            ->andReturn(2);

        $this->mockOrderQuery($query);

        $this->assertSame(2, OrderRepo::claimGuestOrdersForCustomer($this->customer()));
    }

    public function test_registration_can_limit_claim_to_guest_orders_in_current_session(): void
    {
        [$query, $unownedOrders] = $this->queryMocks();
        $query->shouldReceive('whereRaw')
            ->once()
            ->with('LOWER(TRIM(email)) = ?', ['buyer@example.com'])
            ->andReturnSelf();
        $query->shouldReceive('whereIn')
            ->once()
            ->with('number', ['1001', '1002'])
            ->andReturnSelf();
        $query->shouldReceive('update')
            ->once()
            ->with(['customer_id' => 42])
            ->andReturn(1);

        $this->mockOrderQuery($query);

        $this->assertSame(
            1,
            OrderRepo::claimGuestOrdersForCustomerInSession($this->customer(), ['1001', ' 1002 ', '1001', ''])
        );
    }

    public function test_empty_session_order_list_does_not_claim_any_order(): void
    {
        [$query] = $this->queryMocks();
        $query->shouldReceive('whereRaw')
            ->once()
            ->with('LOWER(TRIM(email)) = ?', ['buyer@example.com'])
            ->andReturnSelf();
        $query->shouldReceive('whereIn')->never();
        $query->shouldReceive('update')->never();

        $this->mockOrderQuery($query);

        $this->assertSame(0, OrderRepo::claimGuestOrdersForCustomerInSession($this->customer(), []));
    }

    private function queryMocks(): array
    {
        $query         = Mockery::mock(Builder::class);
        $unownedOrders = Mockery::mock(Builder::class);

        $query->shouldReceive('where')
            ->once()
            ->with(Mockery::on('is_callable'))
            ->andReturnUsing(function (callable $constraint) use ($query, $unownedOrders) {
                $constraint($unownedOrders);

                return $query;
            });
        $unownedOrders->shouldReceive('whereNull')->once()->with('customer_id')->andReturnSelf();
        $unownedOrders->shouldReceive('orWhere')->once()->with('customer_id', 0)->andReturnSelf();

        return [$query, $unownedOrders];
    }

    private function mockOrderQuery(Builder $query): void
    {
        Mockery::mock('alias:Beike\Models\Order')
            ->shouldReceive('query')
            ->once()
            ->andReturn($query);
    }

    private function customer(): Customer
    {
        $customer = (new ReflectionClass(Customer::class))->newInstanceWithoutConstructor();
        $customer->setRawAttributes(['id' => 42, 'email' => ' Buyer@Example.com ']);

        return $customer;
    }
}
