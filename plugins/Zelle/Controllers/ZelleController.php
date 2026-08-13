<?php

namespace Plugin\Zelle\Controllers;

use Beike\Models\Order;
use Beike\Repositories\OrderPaymentRepo;
use Beike\Repositories\OrderRepo;
use Beike\Services\StateMachineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Plugin\Zelle\Services\ZellePaymentService;

class ZelleController
{
    /**
     * 保存买家提交的 Zelle 付款声明，订单状态继续保持待支付。
     */
    public function declare(Request $request, string $number): JsonResponse
    {
        $data = $request->validate([
            'email'          => 'nullable|email|max:255',
            'transaction_id' => 'required|string|max:128',
            'payer_name'     => 'nullable|string|max:255',
            'paid_at'        => 'nullable|date',
            'note'           => 'nullable|string|max:500',
        ]);

        $order = $this->resolveShopOrder($request, $number);
        $this->assertPendingZelleOrder($order);

        DB::transaction(function () use ($order, $data): void {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertPendingZelleOrder($lockedOrder);

            $service = new ZellePaymentService($lockedOrder);
            OrderPaymentRepo::createOrUpdatePayment($lockedOrder->id, $service->customerDeclaration($data));
        });

        return json_success(trans('Zelle::common.declaration_saved'));
    }

    /**
     * 后台核验银行到账信息，通过状态机将订单从待支付变更为已支付。
     */
    public function confirm(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'transaction_id'  => 'required|string|max:128',
            'received_amount' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
            'payer_name'      => 'nullable|string|max:255',
            'received_at'     => 'nullable|date',
            'receipt'         => 'nullable|string|max:1000',
            'note'            => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($order, $data): void {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertPendingZelleOrder($lockedOrder);

            $service = new ZellePaymentService($lockedOrder);
            $payment = $service->verifiedPayment($data);

            // 支付记录由状态机钩子写入，确保状态、库存与审计记录同事务提交。
            StateMachineService::getInstance($lockedOrder)
                ->setPayment($payment)
                ->changeStatus(StateMachineService::PAID, trans('Zelle::common.payment_verified'));
        });

        return redirect(admin_route('orders.show', $order))->with('success', trans('Zelle::common.payment_verified'));
    }

    /**
     * 解析当前买家可访问的订单，游客沿用订单支付页已有的会话和邮箱校验规则。
     */
    private function resolveShopOrder(Request $request, string $number): Order
    {
        $customer = current_customer();
        $order    = OrderRepo::getOrderByNumber($number, $customer);

        if (empty($order)) {
            abort(404);
        }

        if (! $customer && ! $this->isGuestOrderAuthorized($order, $number, (string) $request->input('email', ''))) {
            abort(404);
        }

        return $order;
    }

    /**
     * 限制游客只能操作本人会话中的订单，重新访问时可使用订单邮箱校验。
     */
    private function isGuestOrderAuthorized(Order $order, string $number, string $email): bool
    {
        if ($order->customer_id) {
            return false;
        }

        $guestOrders = array_map('strval', session('guest_order_numbers', []));
        if (in_array($number, $guestOrders, true)) {
            return true;
        }

        return $email !== '' && hash_equals((string) $order->email, trim($email));
    }

    /**
     * 人工声明和到账确认都只接收仍待支付的 Zelle 订单。
     */
    private function assertPendingZelleOrder(Order $order): void
    {
        if ($order->payment_method_code !== ZellePaymentService::CODE || $order->status !== StateMachineService::UNPAID) {
            throw ValidationException::withMessages([
                'order' => trans('Zelle::common.order_not_confirmable'),
            ]);
        }

        ZellePaymentService::assertCurrencyCode((string) $order->currency_code);
    }
}
