<?php

/**
 * OrderProductRepo.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-07-04 21:14:12
 * @modified   2022-07-04 21:14:12
 */

namespace Beike\Repositories;

use Beike\Models\Order;
use Beike\Models\OrderProduct;
use Beike\Services\StateMachineService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Plugin\CyberCloak\Services\CatalogOrderService;
use Plugin\CyberCloak\Services\StoreContext;

class OrderProductRepo
{
    /**
     * 创建商品明细
     *
     * @param Order $order
     * @param       $cartProducts
     */
    public static function createOrderProducts(Order $order, $cartProducts)
    {
        foreach ($cartProducts as $cartProduct) {
            $productName   = $cartProduct['name'];
            $variantLabels = $cartProduct['variant_labels'] ?? '';
            if ($variantLabels) {
                $productName .= " - {$variantLabels}";
            }
            $catalogMode = (string) ($cartProduct['catalog_mode'] ?? StoreContext::REAL);
            if (! in_array($catalogMode, [StoreContext::REAL, StoreContext::PUBLIC], true)) {
                throw new \RuntimeException('订单商品库模式无效');
            }

            $orderProduct = [
                'order_id'                  => $order->id,
                'product_id'                => $cartProduct['product_id'],
                'order_number'              => $order->number,
                'product_sku'               => $cartProduct['product_sku'],
                'name'                      => $productName,
                'image'                     => $cartProduct['image'],
                'quantity'                  => $cartProduct['quantity'],
                'price'                     => $cartProduct['price'],
                'catalog_mode'              => $catalogMode,
                'catalog_product_id'       => $cartProduct['catalog_product_id'] ?? $cartProduct['product_id'],
                'catalog_sku_id'           => $cartProduct['catalog_sku_id'] ?? $cartProduct['sku_id'],
                'fulfillment_sku'          => $cartProduct['fulfillment_sku'] ?? $cartProduct['product_sku'],
                'catalog_mapping_version'  => $cartProduct['catalog_mapping_version']
                    ?? app(CatalogOrderService::class)->mappingVersion($catalogMode),
            ];

            // 展示 SKU 必须绑定已发布映射，避免订单创建后再依赖当前商品库解析履约身份。
            if ($catalogMode === StoreContext::PUBLIC && trim((string) $orderProduct['fulfillment_sku']) === '') {
                throw new \RuntimeException('展示订单缺少履约 SKU');
            }

            $orderProduct = OrderProduct::create($orderProduct);
            hook_filter('repository.order_product.create.after', ['order_product' => $orderProduct, 'cart_product' => $cartProduct]);
        }
    }

    /**
     * 查找单条商品明细数据
     *
     * @param $id
     * @return Builder|Builder[]|Collection|Model|null
     */
    public static function find($id): Model|Collection|Builder|array|null
    {
        return OrderProduct::query()->findOrFail($id);
    }

    /**
     * @param array $filters
     * @return Builder
     */
    public static function getBuilder(array $filters = []): Builder
    {
        $builder = OrderProduct::query()->with(['order', 'product.description']);

        $order_statuses = $filters['order_statuses'] ?? StateMachineService::getValidStatuses();
        if ($order_statuses) {
            $builder->whereHas('order', function ($query) use ($order_statuses) {
                $query->whereIn('status', $order_statuses);
            });
        } else {
            $builder->whereHas('order', function ($query) {
                $query->where('status', '<>', StateMachineService::CREATED);
            });
        }

        $start = $filters['date_start'] ?? null;
        if ($start) {
            $builder->where('created_at', '>=', $start);
        }

        $end = $filters['date_end'] ?? null;
        if ($end) {
            $builder->where('created_at', '<', Carbon::createFromFormat('Y-m-d', $end)->subDay());
        }

        $order = $filters['order'] ?? null;
        if ($order) {
            $builder->orderBy($order, 'desc');
        }

        $limit = $filters['limit'] ?? null;
        if ($limit) {
            $builder->limit($limit);
        }

        if (getDBDriver() == 'mysql') {
            $expression = '`product_id`, SUM(`quantity`) AS total_quantity, SUM(`price` * `quantity`) AS total_amount';
        } else {
            $expression = 'product_id, SUM(quantity) AS total_quantity, SUM(price * quantity) AS total_amount';
        }

        $builder->groupBy(['product_id'])
            ->selectRaw($expression);

        return $builder;
    }
}
