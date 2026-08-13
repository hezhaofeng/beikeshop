<?php

namespace Plugin\TieredShipping;

use Beike\Admin\Http\Resources\PluginResource;
use Beike\Plugin\Plugin;
use Beike\Shop\Services\CheckoutService;
use Plugin\TieredShipping\Services\TieredShippingService;

class Bootstrap
{
    /**
     * 注册后台配置页和配置保存时的条件校验。
     */
    public function boot(): void
    {
        add_hook_blade('admin.plugin.form', function ($callback, $output, $data) {
            if (($data['plugin']->code ?? '') !== TieredShippingService::CODE) {
                return $output;
            }

            return view('TieredShipping::admin.config_form', $data)->render();
        }, 1);

        add_hook_action('admin.plugin.update.before', function (array $data): void {
            if (($data['plugin_code'] ?? '') !== TieredShippingService::CODE) {
                return;
            }

            TieredShippingService::validateConfiguration($data['fields'] ?? [])->validate();
        });
    }

    /**
     * 提供一个配送报价，费用与订单总额计算共用同一规则。
     */
    public function getQuotes(CheckoutService $checkout, Plugin $plugin): array
    {
        $pluginResource = (new PluginResource($plugin))->jsonSerialize();

        return [[
            'type'        => 'shipping',
            'code'        => $plugin->code . '.0',
            'name'        => $pluginResource['name'],
            'description' => $pluginResource['description'],
            'icon'        => $pluginResource['icon'],
            'cost'        => $this->getShippingFee($checkout),
        ]];
    }

    /**
     * 根据当前已选商品的小计和件数计算运费。
     */
    public function getShippingFee(CheckoutService $checkout): float
    {
        $totalService = $checkout->totalService;

        return (new TieredShippingService)->calculate(
            (float) $totalService->getSubTotal(),
            (int) $totalService->countProducts(),
            plugin_setting(TieredShippingService::CODE, [])
        );
    }
}
