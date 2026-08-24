<?php

namespace Plugin\OfflineTransfer\Services;

use Beike\Shop\Services\PaymentService;
use Illuminate\Validation\ValidationException;

class OfflineTransferPaymentService extends PaymentService
{
    public const CODE = 'offline_transfer';

    public const QUANTITY_RESTRICTION_ENABLED   = 'quantity_restriction_enabled';

    public const QUANTITY_RESTRICTION_THRESHOLD = 'quantity_restriction_threshold';

    public const DISCOUNT_PERCENTAGE = 'discount_percentage';

    /**
     * 初始化服务时限制订单必须使用线下转账，避免其他支付方式复用人工核验流程。
     */
    public function __construct($order)
    {
        parent::__construct($order);

        if ($this->paymentMethodCode !== self::CODE) {
            throw new \LogicException('订单支付方式不是线下转账。');
        }
    }

    /**
     * 生成支付页面和移动端共用的转账说明。
     */
    public function instruction(array $setting = []): array
    {
        $transferInstruction = trim((string) ($setting['transfer_instruction'] ?? ''));
        if ($transferInstruction === '') {
            throw ValidationException::withMessages([
                'transfer_instruction' => trans('OfflineTransfer::common.transfer_instruction_not_configured'),
            ]);
        }

        return [
            'transfer_instruction' => $transferInstruction,
            'receipt_required'     => self::requiresReceipt($setting['receipt_required'] ?? true),
            'amount'               => self::formatAmount($this->order->total),
            'currency'             => (string) $this->order->currency_code,
            'order_number'         => $this->order->number,
        ];
    }

    /**
     * 返回移动端可展示的人工转账参数，不在客户端生成已支付状态。
     */
    public function mobilePaymentData(array $setting = []): array
    {
        return [
            'payment_type' => self::CODE,
            'status'       => 'pending_verification',
            'instruction'  => $this->instruction($setting),
        ];
    }

    /**
     * 将买家填写的转账信息和凭证路径保存为待审核申报，不改变订单状态。
     */
    public function customerDeclaration(array $data, ?string $receiptPath): array
    {
        $payment = [
            'request' => [
                'channel'         => self::CODE,
                'source'          => 'customer_declaration',
                'order_number'    => $this->order->number,
                'expected_amount' => self::formatAmount($this->order->total),
                'currency'        => (string) $this->order->currency_code,
                'transaction_id'  => trim((string) ($data['transaction_id'] ?? '')),
                'payer_name'      => trim((string) ($data['payer_name'] ?? '')),
                'paid_at'         => $data['paid_at'] ?? null,
                'note'            => trim((string) ($data['note'] ?? '')),
                'declared_at'     => now()->toIso8601String(),
            ],
        ];

        if ($receiptPath !== null) {
            $payment['receipt'] = $receiptPath;
        }

        return $payment;
    }

    /**
     * 验证到账金额并生成最终支付审计数据。
     */
    public function verifiedPayment(array $data): array
    {
        $receivedAmount = self::formatAmount($data['received_amount'] ?? '');
        $expectedAmount = self::formatAmount($this->order->total);
        if (! hash_equals($expectedAmount, $receivedAmount)) {
            throw ValidationException::withMessages([
                'received_amount' => trans('OfflineTransfer::common.amount_mismatch', [
                    'amount'   => $expectedAmount,
                    'currency' => $this->order->currency_code,
                ]),
            ]);
        }

        return [
            'transaction_id' => trim((string) $data['transaction_id']),
            'response'       => [
                'channel'         => self::CODE,
                'source'          => 'admin_verification',
                'order_number'    => $this->order->number,
                'expected_amount' => $expectedAmount,
                'received_amount' => $receivedAmount,
                'currency'        => (string) $this->order->currency_code,
                'payer_name'      => trim((string) ($data['payer_name'] ?? '')),
                'received_at'     => $data['received_at'] ?? null,
                'note'            => trim((string) ($data['note'] ?? '')),
                'verified_at'     => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * 将金额格式固定为两位小数，作为后台到账金额的精确比对值。
     */
    public static function formatAmount($amount): string
    {
        if (! is_numeric($amount)) {
            throw ValidationException::withMessages([
                'received_amount' => trans('OfflineTransfer::common.invalid_amount'),
            ]);
        }

        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * 插件设置来自数据库字符串，必须显式解析以识别字符串值 "0"。
     */
    public static function requiresReceipt($value): bool
    {
        return in_array($value, [true, 1, '1'], true);
    }

    /**
     * 读取并限制线下转账折扣百分比，避免配置值影响其他支付方式。
     */
    public static function discountPercentage(array $setting): float
    {
        $percentage = $setting[self::DISCOUNT_PERCENTAGE] ?? 0;
        if (! is_numeric($percentage)) {
            return 0.0;
        }

        return min(100.0, max(0.0, (float) $percentage));
    }

    public static function paymentMethodName(string $name, array $setting): string
    {
        $percentage = self::discountPercentage($setting);
        if ($percentage <= 0) {
            return $name;
        }

        $formatted = rtrim(rtrim(number_format($percentage, 2, '.', ''), '0'), '.');

        return $name . ' (' . $formatted . '% discount)';
    }

    /**
     * 判断当前购物车是否超过后台配置的线下支付件数门槛。
     */
    public static function shouldForceOfflinePayment(array $setting, int $quantity): bool
    {
        if (! self::requiresReceipt($setting[self::QUANTITY_RESTRICTION_ENABLED] ?? false)) {
            return false;
        }

        $threshold = filter_var($setting[self::QUANTITY_RESTRICTION_THRESHOLD] ?? null, FILTER_VALIDATE_INT);
        if ($threshold === false || $threshold < 1) {
            return false;
        }

        return $quantity > $threshold;
    }

    /**
     * 从结账数据读取已选商品总件数，兼容旧插件可能传入的商品列表格式。
     */
    public static function checkoutQuantity(array $checkoutData): int
    {
        $quantity = data_get($checkoutData, 'carts.quantity');
        if (is_numeric($quantity)) {
            return max(0, (int) $quantity);
        }

        $products = data_get($checkoutData, 'carts.carts', data_get($checkoutData, 'carts', []));
        if (! is_array($products)) {
            return 0;
        }

        return max(0, (int) collect($products)->sum('quantity'));
    }
}
