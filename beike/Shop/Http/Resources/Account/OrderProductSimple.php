<?php

/**
 * OrderProductList.php
 *
 * @copyright  2023 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2023-08-14 18:41:19
 * @modified   2023-08-14 18:41:19
 */

namespace Beike\Shop\Http\Resources\Account;

use Beike\Repositories\RmaRepo;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderProductSimple extends JsonResource
{
    public function toArray($request): array
    {
        $data = [
            'id'            => $this->id,
            'product_id'    => $this->product_id,
            'catalog_mode'  => $this->catalog_mode ?: 'real',
            'catalog_product_id' => $this->catalog_product_id ?: $this->product_id,
            'catalog_sku_id' => $this->catalog_sku_id,
            'fulfillment_sku' => $this->fulfillment_sku ?: $this->product_sku,
            'catalog_mapping_version' => $this->catalog_mapping_version,
            'name'          => $this->name,
            'sku'           => $this->product_sku,
            'quantity'      => $this->quantity,
            'rma_quantity'  => (int) RmaRepo::getRmaQuantity($this->id),
            'price'         => currency_format($this->price),
            'total'         => $this->price * $this->quantity,
            'total_format'  => currency_format($this->price * $this->quantity),
            'image'         => image_resize($this->image),
        ];

        return $data;
    }
}
