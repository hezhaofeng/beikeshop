<?php

namespace Plugin\InsuranceFee\Services;

use Beike\Shop\Services\CheckoutService;

class InsuranceFeeTotalService
{
    /**
     * 为当前结算生成保险费明细，金额会进入订单总额和支付金额。
     */
    public static function getTotal(CheckoutService $checkout): ?array
    {
        $service      = new InsuranceFeeService;
        $feeUsd       = $service->normalizeUsdFee(plugin_setting(InsuranceFeeService::CODE . '.insurance_fee_usd', 0));
        $baseCurrency = (string) system_setting('base.currency', InsuranceFeeService::USD);
        $quantity     = $checkout->totalService->countProducts();
        $amount       = $service->calculateQuantityAmount($feeUsd, $quantity, $baseCurrency);

        if ($amount <= 0) {
            return null;
        }

        $totalData = [
            'code'          => InsuranceFeeService::CODE,
            'title'         => trans('InsuranceFee::common.insurance_fee'),
            'amount'        => $amount,
            'amount_format' => currency_format($amount),
            // 保留配置来源和计费件数，便于后台追溯订单创建时的保险费计算。
            'reference'     => [
                'configured_usd_fee_per_item' => number_format($feeUsd, 2, '.', ''),
                'product_quantity'           => (int) $quantity,
                'base_currency'              => $baseCurrency ?: InsuranceFeeService::USD,
            ],
        ];

        $checkout->totalService->amount += $amount;
        $checkout->totalService->totals[] = $totalData;

        return $totalData;
    }
}
