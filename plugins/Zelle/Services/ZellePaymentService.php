<?php

namespace Plugin\Zelle\Services;

use Beike\Shop\Services\PaymentService;
use Illuminate\Validation\ValidationException;

class ZellePaymentService extends PaymentService
{
    public const CODE = 'zelle';

    public const SUPPORTED_CURRENCY = 'USD';

    /**
     * 初始化服务时限制订单必须使用 Zelle，避免其他支付方式复用其人工核验流程。
     */
    public function __construct($order)
    {
        parent::__construct($order);

        if ($this->paymentMethodCode !== self::CODE) {
            throw new \LogicException('订单支付方式不是 Zelle。');
        }
    }

    /**
     * 校验订单或结账上下文是否使用 USD。
     */
    public static function assertCurrencyCode(string $currencyCode): void
    {
        if (strtoupper($currencyCode) !== self::SUPPORTED_CURRENCY) {
            throw ValidationException::withMessages([
                'payment_method_code' => trans('Zelle::common.currency_only_usd'),
            ]);
        }
    }

    /**
     * 生成支付页面和移动端共用的收款信息。
     */
    public function instruction(array $setting = []): array
    {
        self::assertCurrencyCode((string) $this->order->currency_code);

        $recipientType       = $setting['recipient_type'] ?? 'email';
        $recipientIdentifier = trim((string) ($setting['recipient_identifier'] ?? ''));
        $recipientName       = trim((string) ($setting['recipient_name'] ?? ''));

        if ($recipientIdentifier === '' || $recipientName === '') {
            throw ValidationException::withMessages([
                'recipient_identifier' => trans('Zelle::common.recipient_not_configured'),
            ]);
        }

        return [
            'recipient_type'       => $recipientType,
            'recipient_type_label' => $recipientType === 'mobile'
                ? trans('Zelle::common.recipient_mobile')
                : trans('Zelle::common.recipient_email'),
            'recipient_identifier' => $recipientIdentifier,
            'recipient_name'       => $recipientName,
            'amount'               => self::formatAmount($this->order->total),
            'currency'             => self::SUPPORTED_CURRENCY,
            'order_number'         => $this->order->number,
            'payment_note'         => trim((string) ($setting['payment_note'] ?? '')),
        ];
    }

    /**
     * 返回移动端可直接展示的人工转账参数，不在客户端生成已支付状态。
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
     * 将买家填写的交易号保存为声明信息，供后台与银行到账记录核对。
     */
    public function customerDeclaration(array $data): array
    {
        return [
            'request' => [
                'channel'          => self::CODE,
                'source'           => 'customer_declaration',
                'order_number'     => $this->order->number,
                'expected_amount'  => self::formatAmount($this->order->total),
                'currency'         => self::SUPPORTED_CURRENCY,
                'transaction_id'   => trim((string) ($data['transaction_id'] ?? '')),
                'payer_name'       => trim((string) ($data['payer_name'] ?? '')),
                'paid_at'          => $data['paid_at'] ?? null,
                'note'             => trim((string) ($data['note'] ?? '')),
                'declared_at'      => now()->toIso8601String(),
            ],
        ];
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
                'received_amount' => trans('Zelle::common.amount_mismatch', ['amount' => $expectedAmount]),
            ]);
        }

        $payment = [
            'transaction_id' => trim((string) $data['transaction_id']),
            'response'       => [
                'channel'         => self::CODE,
                'source'          => 'admin_verification',
                'order_number'    => $this->order->number,
                'expected_amount' => $expectedAmount,
                'received_amount' => $receivedAmount,
                'currency'        => self::SUPPORTED_CURRENCY,
                'payer_name'      => trim((string) ($data['payer_name'] ?? '')),
                'received_at'     => $data['received_at'] ?? null,
                'note'            => trim((string) ($data['note'] ?? '')),
                'verified_at'     => now()->toIso8601String(),
            ],
        ];

        $receipt = trim((string) ($data['receipt'] ?? ''));
        if ($receipt !== '') {
            $payment['receipt'] = $receipt;
        }

        return $payment;
    }

    /**
     * 将金额格式固定为两位小数，作为后台到账金额的精确比对值。
     */
    public static function formatAmount($amount): string
    {
        if (! is_numeric($amount)) {
            throw ValidationException::withMessages([
                'received_amount' => trans('Zelle::common.invalid_amount'),
            ]);
        }

        return number_format((float) $amount, 2, '.', '');
    }
}
