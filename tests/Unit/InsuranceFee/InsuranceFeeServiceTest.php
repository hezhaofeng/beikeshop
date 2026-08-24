<?php

namespace Tests\Unit\InsuranceFee;

use Beike\Services\CurrencyService;
use PHPUnit\Framework\TestCase;
use Plugin\InsuranceFee\Services\InsuranceFeeService;
use Plugin\InsuranceFee\Services\InsuranceFeeTotalService;

class InsuranceFeeServiceTest extends TestCase
{
    /**
     * 配置的每件商品 USD 保险费需要换算，并按商品件数参与订单计算。
     */
    public function test_usd_fee_is_converted_to_store_base_currency(): void
    {
        $currencyService = new class extends CurrencyService
        {
            public function __construct()
            {
            }

            public function convert($value, $from, $to)
            {
                return $from === 'USD' && $to === 'EUR' ? $value * 0.92 : $value;
            }
        };

        $service = new InsuranceFeeService($currencyService);

        $this->assertSame(9.2, $service->calculateBaseAmount('10.00', 'EUR'));
        $this->assertSame(27.6, $service->calculateQuantityAmount('10.00', 3, 'EUR'));
    }

    /**
     * 存量设置被修改为无效值时，不应向客户增加意外费用。
     */
    public function test_invalid_or_non_positive_fee_is_not_charged(): void
    {
        $currencyService = new class extends CurrencyService
        {
            public function __construct()
            {
            }

            public function convert($value, $from, $to)
            {
                return $value;
            }
        };

        $service = new InsuranceFeeService($currencyService);

        $this->assertSame(0.0, $service->calculateBaseAmount('invalid', 'USD'));
        $this->assertSame(0.0, $service->calculateBaseAmount('-1', 'USD'));
        $this->assertSame(0.0, $service->calculateBaseAmount(0, 'USD'));
        $this->assertSame(0.0, $service->calculateQuantityAmount('10', 0, 'USD'));
        $this->assertSame(0.0, $service->calculateQuantityAmount('10', '2.5', 'USD'));
    }

    /**
     * 保险费总额服务必须位于订单总计之前，且同一费用项只注册一次。
     */
    public function test_insurance_total_service_is_registered_once(): void
    {
        $maps = InsuranceFeeService::addToTotalMaps([
            'subtotal' => 'SubtotalService',
        ]);

        $this->assertSame(InsuranceFeeTotalService::class, $maps[InsuranceFeeService::CODE]);
        $this->assertSame($maps, InsuranceFeeService::addToTotalMaps($maps));
    }

    /**
     * 插件声明与后台配置表单必须包含每件商品固定 USD 保险费字段。
     */
    public function test_plugin_configuration_files_are_valid(): void
    {
        $basePath = dirname(__DIR__, 3);
        $config   = json_decode(file_get_contents($basePath . '/plugins/InsuranceFee/config.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(InsuranceFeeService::CODE, $config['code'] ?? null);
        $this->assertSame('feature', $config['type'] ?? null);

        $columns = require $basePath . '/plugins/InsuranceFee/columns.php';
        $this->assertSame('required|numeric|decimal:0,2|gt:0|max:99999999.99', $columns[0]['rules'] ?? null);

        $form = file_get_contents($basePath . '/plugins/InsuranceFee/Views/admin/config_form.blade.php');

        $this->assertStringContainsString('name="insurance_fee_usd"', $form);
        $this->assertStringContainsString('groupRight="USD"', $form);
    }
}
