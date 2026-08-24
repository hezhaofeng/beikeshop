<?php

namespace Plugin\OfflineTransfer\Services;

use Beike\Shop\Services\CheckoutService;

class OfflineTransferDiscountService
{
    /**
     * 仅使用商品金额和保险费作为折扣基数，运费及其他总额项目不参与折扣。
     */
    public static function discountBase(array $totals): float
    {
        return round((float) collect($totals)
            ->whereIn('code', ['sub_total', 'insurance_fee'])
            ->sum('amount'), 2);
    }

    public static function getTotal(CheckoutService $checkout): ?array
    {
        $totalService = $checkout->totalService;
        if ($totalService->getCurrentCart()->payment_method_code !== OfflineTransferPaymentService::CODE) {
            return null;
        }

        $percentage = OfflineTransferPaymentService::discountPercentage(
            plugin_setting(OfflineTransferPaymentService::CODE, [])
        );
        $discountBase = self::discountBase($totalService->totals);
        if ($percentage <= 0 || $discountBase <= 0) {
            return null;
        }

        $amount    = round($discountBase * $percentage / 100, 2);
        $totalData = [
            'code'          => 'offline_transfer_discount',
            'title'         => trans('OfflineTransfer::common.discount'),
            'amount'        => -$amount,
            'amount_format' => currency_format(-$amount),
        ];

        $totalService->amount += $totalData['amount'];
        $totalService->totals[] = $totalData;

        return $totalData;
    }
}
