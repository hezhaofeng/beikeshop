<?php

/**
 * CartRepo.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-07-04 17:14:14
 * @modified   2022-07-04 17:14:14
 */

namespace Beike\Repositories;

use Beike\Models\Cart;
use Beike\Models\CartProduct;
use Beike\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Plugin\CyberCloak\Services\CatalogCartItemService;

class CartRepo
{
    /**
     * 创建购物车
     *
     * @param $customer
     * @return Cart
     */
    public static function createCart($customer)
    {
        if (is_numeric($customer)) {
            $customer = Customer::query()->find($customer);
        }
        $customerId = $customer->id ?? 0;
        $sessionId  = get_session_id();
        if ($customerId) {
            $cart = Cart::query()->where('customer_id', $customerId)->first();
        } else {
            $cart = Cart::query()->where('session_id', $sessionId)->first();
        }
        $defaultAddressId = $customer->address_id ?? 0;
        if (empty($defaultAddressId) && $customer) {
            $firstAddress     = AddressRepo::listByCustomer($customer)->first();
            $defaultAddressId = $firstAddress->id ?? 0;
        }

        if (empty($cart)) {
            $shippingMethod     = PluginRepo::getShippingMethods()->first();
            $shippingMethodCode = $shippingMethod->code ?? '';
            $paymentMethod      = PluginRepo::getPaymentMethods()->first();
            $cart               = Cart::query()->create([
                'customer_id'          => $customerId,
                'session_id'           => $sessionId,
                'shipping_address_id'  => $defaultAddressId,
                'shipping_method_code' => $shippingMethodCode ? $shippingMethodCode . '.0' : '',
                'payment_address_id'   => $defaultAddressId,
                'payment_method_code'  => $paymentMethod->code ?? '',
            ]);
        } else {
            if ($cart->shipping_address_id == 0 || empty(AddressRepo::find($cart->shipping_address_id))) {
                $cart->shipping_address_id = $defaultAddressId;
            }
            if ($cart->payment_address_id == 0 || empty(AddressRepo::find($cart->payment_address_id))) {
                $cart->payment_address_id = $defaultAddressId;
            }
            $cart->save();
        }
        $cart->loadMissing(['shippingAddress', 'paymentAddress']);
        $cart->extra                  = json_decode($cart->extra, true);
        $cart->guest_shipping_address = json_decode($cart->guest_shipping_address, true);
        $cart->guest_payment_address  = json_decode($cart->guest_payment_address, true);

        return $cart;
    }

    /**
     * 清空购物车以及购物车已选中商品
     *
     * @param $customer
     */
    public static function clearSelectedCartProducts($customer)
    {
        if (is_numeric($customer)) {
            $customer = Customer::query()->find($customer);
        }
        $customerId = $customer->id ?? 0;
        if ($customer) {
            Cart::query()->where('customer_id', $customerId)->delete();
        } else {
            Cart::query()->where('session_id', get_session_id())->delete();
        }
        self::selectedCartProductsBuilder($customerId)->delete();
    }

    public static function shippingRequired($customerId)
    {
        $cartList           = self::selectedCartProducts($customerId);
        foreach ($cartList as $item) {
            if ($item->product && $item->product->shipping) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取已选中购物车商品列表
     *
     * @param $customerId
     * @return Builder[]|Collection
     */
    public static function selectedCartProducts($customerId)
    {
        $cartProducts = self::selectedCartProductsBuilder($customerId)->get();
        $cartProducts = self::hydrateCatalogItems($cartProducts);

        $cartProducts = hook_filter('cart.repo.selected.products', $cartProducts);

        return $cartProducts;
    }

    /**
     * 已选中购物车商品 builder
     *
     * @param $customerId
     * @return Builder
     */
    public static function selectedCartProductsBuilder($customerId): Builder
    {
        return self::allCartProductsBuilder($customerId)->where('selected', true);
    }

    /**
     * 获取所有购物车商品列表
     *
     * @param $customerId
     * @return Builder[]|Collection
     */
    public static function allCartProducts($customerId)
    {
        return self::allCartProductsBuilder($customerId)->get();
    }

    /**
     * 当前购物车所有商品 builder
     *
     * @param $customerId
     * @return Builder
     */
    public static function allCartProductsBuilder($customerId): Builder
    {
        // 商品关系由 CatalogCartItemService 按每条明细的 catalog_mode 显式加载。
        $builder = CartProduct::query();
        if ($customerId) {
            $builder->where('customer_id', $customerId);
        } else {
            $builder->where('session_id', get_session_id());
        }
        $builder->orderByDesc('id');

        return $builder;
    }

    /**
     * @param $customer
     * @return void
     */
    public static function mergeGuestCart($customer, $guestCartProduct): void
    {
        foreach ($guestCartProduct as $cartProduct) {
            $mode      = $cartProduct->catalog_mode ?: 'real';
            $productId = $cartProduct->catalog_product_id ?: $cartProduct->product_id;
            $builder   = self::allCartProductsBuilder($customer->id)
                ->where('catalog_mode', $mode)
                ->where('catalog_product_id', $productId);
            if ($cartProduct->catalog_sku_id) {
                $builder->where('catalog_sku_id', $cartProduct->catalog_sku_id);
            } else {
                $builder->where('product_sku', $cartProduct->product_sku);
            }
            $builder->delete();
            $cartProduct->customer_id = $customer->id;
            $cartProduct->save();
        }
    }

    /**
     * 让购物车仓储返回每条明细所属商品库的商品和 SKU。
     */
    private static function hydrateCatalogItems(Collection $cartProducts): Collection
    {
        $catalog = app(CatalogCartItemService::class);

        return $cartProducts->filter(fn (CartProduct $cartProduct): bool => $catalog->hydrate($cartProduct))->values();
    }
}
