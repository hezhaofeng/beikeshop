<?php

namespace Plugin\InsuranceFee;

use Plugin\InsuranceFee\Services\InsuranceFeeService;

class Bootstrap
{
    /**
     * 将保险费插入结算总额，订单总计会自然包含该费用。
     */
    public function boot(): void
    {
        add_hook_filter('service.total.maps', [InsuranceFeeService::class, 'addToTotalMaps']);
    }
}
