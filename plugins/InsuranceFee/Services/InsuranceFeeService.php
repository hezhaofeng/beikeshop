<?php

namespace Plugin\InsuranceFee\Services;

use Beike\Services\CurrencyService;

class InsuranceFeeService
{
    public const CODE = 'insurance_fee';

    public const USD = 'USD';

    private CurrencyService $currencyService;

    public function __construct(CurrencyService $currencyService = null)
    {
        $this->currencyService = $currencyService ?? CurrencyService::getInstance();
    }

    /**
     * 将每件商品的固定 USD 保险费换算为系统基础货币。
     */
    public function calculateBaseAmount(mixed $feeUsd, string $baseCurrency): float
    {
        $feeUsd = $this->normalizeUsdFee($feeUsd);
        if ($feeUsd <= 0) {
            return 0.0;
        }

        $amount = (float) $this->currencyService->convert($feeUsd, self::USD, $baseCurrency ?: self::USD);
        if (! is_finite($amount) || $amount <= 0) {
            return 0.0;
        }

        return round($amount, 8);
    }

    /**
     * 按商品总件数计算保险费，避免将多件商品按单笔订单重复收取一次。
     */
    public function calculateQuantityAmount(mixed $feeUsd, mixed $quantity, string $baseCurrency): float
    {
        $quantity = filter_var($quantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($quantity === false) {
            return 0.0;
        }

        return round($this->calculateBaseAmount($feeUsd, $baseCurrency) * $quantity, 8);
    }

    /**
     * 兼容旧配置或被手工修改的值，异常金额不参与订单计算。
     */
    public function normalizeUsdFee(mixed $feeUsd): float
    {
        if (! is_numeric($feeUsd)) {
            return 0.0;
        }

        $feeUsd = (float) $feeUsd;

        return is_finite($feeUsd) && $feeUsd > 0 ? $feeUsd : 0.0;
    }

    /**
     * 在订单总计前追加保险费，并避免重复注册同一费用项。
     */
    public static function addToTotalMaps(array $maps): array
    {
        if (isset($maps[self::CODE])) {
            return $maps;
        }

        $maps[self::CODE] = InsuranceFeeTotalService::class;

        return $maps;
    }
}
