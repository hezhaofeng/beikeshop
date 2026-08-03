<?php

/**
 * CartRequest.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-08-26 14:21:32
 * @modified   2022-08-26 14:21:32
 */

namespace Beike\Shop\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Plugin\CyberCloak\Services\CatalogCartItemService;

class CartRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $skuId = (int) $this->get('sku_id');

        return [
            'sku_id'   => 'required|int',
            'quantity' => ['required', 'int', 'min:1', function ($attribute, $value, $fail) use ($skuId) {
                $catalog = app(CatalogCartItemService::class);
                $sku     = $catalog->findSellableSkuById($catalog->currentMode(), $skuId);
                if (! $sku || $value > $sku->quantity) {
                    $fail(trans('cart.stock_out'));
                }
            }],
            'buy_now'  => 'bool',
        ];
    }

    public function attributes()
    {
        return [
            'sku_id'   => trans('cart.sku_id'),
            'quantity' => trans('cart.quantity'),
        ];
    }
}
