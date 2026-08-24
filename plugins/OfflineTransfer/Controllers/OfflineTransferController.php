<?php

namespace Plugin\OfflineTransfer\Controllers;

use Beike\Models\Order;
use Beike\Repositories\OrderPaymentRepo;
use Beike\Repositories\OrderRepo;
use Beike\Services\StateMachineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Plugin\OfflineTransfer\Services\OfflineTransferPaymentService;

class OfflineTransferController
{
    /**
     * 展示公共订单查询页，所有支付方式的游客订单均可使用。
     */
    public function orderLookup()
    {
        return view('OfflineTransfer::shop.order_lookup');
    }

    /**
     * 通过订单号和下单邮箱跳转到已有的公共订单详情页。
     */
    public function findOrder(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'number' => 'required|digits_between:1,64',
            'email'  => 'required|email|max:255',
        ]);

        return redirect(shop_route('orders.show', [
            'number' => trim($data['number']),
            'email'  => trim($data['email']),
        ]));
    }

    /**
     * 保存买家提交的转账申报和凭证，订单状态继续保持待支付。
     */
    public function declare(Request $request, string $number): JsonResponse
    {
        $data = $request->validate([
            'email'          => 'nullable|email|max:255',
            'transaction_id' => 'nullable|string|max:128',
            'payer_name'     => 'nullable|string|max:255',
            'paid_at'        => 'nullable|date',
            'receipt'        => 'nullable|file|mimes:jpg,jpeg,png,gif,webp,pdf|mimetypes:image/jpeg,image/png,image/gif,image/webp,application/pdf|max:10240',
            'note'           => 'nullable|string|max:500',
        ]);

        $order = $this->resolveShopOrder($request, $number);
        $this->assertPendingOfflineTransferOrder($order);
        $existingReceipt    = $order->orderPayments()->value('receipt');
        $hasExistingReceipt = $this->isReceiptPath($existingReceipt) && Storage::disk('local')->exists($existingReceipt);
        $this->validateDeclaration($data, $hasExistingReceipt);

        $receiptPath     = $this->storeReceipt($request, $order);
        $previousReceipt = null;

        try {
            DB::transaction(function () use ($order, $data, $receiptPath, &$previousReceipt): void {
                $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
                $this->assertPendingOfflineTransferOrder($lockedOrder);
                $previousReceipt = $lockedOrder->orderPayments()->value('receipt');

                $service = new OfflineTransferPaymentService($lockedOrder);
                OrderPaymentRepo::createOrUpdatePayment(
                    $lockedOrder->id,
                    $service->customerDeclaration($data, $receiptPath)
                );
            });
        } catch (\Throwable $exception) {
            if ($receiptPath !== null) {
                Storage::disk('local')->delete($receiptPath);
            }

            throw $exception;
        }

        if ($receiptPath !== null && $this->isReceiptPath($previousReceipt) && $previousReceipt !== $receiptPath) {
            Storage::disk('local')->delete($previousReceipt);
        }

        return json_success(trans('OfflineTransfer::common.declaration_saved'));
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
            'note'            => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($order, $data): void {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertPendingOfflineTransferOrder($lockedOrder);

            $service = new OfflineTransferPaymentService($lockedOrder);
            $payment = $service->verifiedPayment($data);

            // 支付记录由状态机钩子写入，确保状态、库存与审计记录同事务提交。
            StateMachineService::getInstance($lockedOrder)
                ->setPayment($payment)
                ->changeStatus(StateMachineService::PAID, $this->paymentVerifiedComment($lockedOrder));
        });

        return redirect(admin_route('orders.show', $order))->with('success', trans('OfflineTransfer::common.payment_verified'));
    }

    /**
     * 订单历史是买家可见的审计记录，应以订单创建时的语言保存，不能受当前后台语言影响。
     */
    private function paymentVerifiedComment(Order $order): string
    {
        $currentLocale = App::getLocale();
        $orderLocale   = trim((string) $order->locale);

        if ($orderLocale !== '') {
            App::setLocale($orderLocale);
        }

        try {
            return trans('OfflineTransfer::common.payment_verified');
        } finally {
            App::setLocale($currentLocale);
        }
    }

    /**
     * 为后台具备订单状态更新权限的用户提供私有凭证预览。
     */
    public function receipt(Order $order)
    {
        $this->assertOfflineTransferOrder($order);

        $payment = $order->orderPayments()->first();
        $path    = $payment?->receipt;
        if (! $this->isReceiptPath($path) || ! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return Storage::disk('local')->response($path);
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
     * 凭证强制开关由当前插件设置决定，申报至少应包含交易号或凭证。
     */
    private function validateDeclaration(array $data, bool $hasExistingReceipt = false): void
    {
        $setting         = plugin_setting(OfflineTransferPaymentService::CODE, []);
        $receiptRequired = OfflineTransferPaymentService::requiresReceipt($setting['receipt_required'] ?? true);
        $hasReceipt      = isset($data['receipt']);
        $transactionId   = trim((string) ($data['transaction_id'] ?? ''));

        if ($receiptRequired && ! $hasReceipt && ! $hasExistingReceipt) {
            throw ValidationException::withMessages([
                'receipt' => trans('OfflineTransfer::common.receipt_required_error'),
            ]);
        }

        if (! $hasReceipt && ! $hasExistingReceipt && $transactionId === '') {
            throw ValidationException::withMessages([
                'transaction_id' => trans('OfflineTransfer::common.declaration_evidence_required'),
            ]);
        }
    }

    /**
     * 将买家凭证保存到不公开的本地存储，路径按订单隔离。
     */
    private function storeReceipt(Request $request, Order $order): ?string
    {
        $file = $request->file('receipt');
        if ($file === null) {
            return null;
        }

        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension());
        $fileName  = Str::uuid()->toString() . '.' . $extension;

        return $file->storeAs('offline-transfer-receipts/' . $order->id, $fileName, 'local');
    }

    /**
     * 人工声明和到账确认都只接收仍待支付的线下转账订单。
     */
    private function assertPendingOfflineTransferOrder(Order $order): void
    {
        $this->assertOfflineTransferOrder($order);

        if ($order->status !== StateMachineService::UNPAID) {
            throw ValidationException::withMessages([
                'order' => trans('OfflineTransfer::common.order_not_confirmable'),
            ]);
        }
    }

    /**
     * 私有凭证只能关联线下转账订单，防止跨支付方式读取文件。
     */
    private function assertOfflineTransferOrder(Order $order): void
    {
        if ($order->payment_method_code !== OfflineTransferPaymentService::CODE) {
            abort(404);
        }
    }

    /**
     * 仅接受本插件生成的私有凭证路径，防止数据库值被用于读取任意文件。
     */
    private function isReceiptPath(?string $path): bool
    {
        return is_string($path) && preg_match('#^offline-transfer-receipts/\d+/[a-f0-9-]+\.(?:jpg|jpeg|png|gif|webp|pdf)$#', $path) === 1;
    }
}
