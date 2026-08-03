<?php

namespace Tests\Unit\CyberCloak;

use Beike\Models\OrderProduct;
use Beike\Models\Order;
use Plugin\CyberCloak\Services\CatalogOrderService;
use RuntimeException;
use Tests\TestCase;

class CatalogOrderTest extends TestCase
{
    /**
     * 展示订单待审核或驳回时不能被支付回调推进为已支付。
     */
    public function test_public_order_payment_requires_review_approval(): void
    {
        $service = new CatalogOrderService;
        $pending = new Order(['catalog_review_status' => 'pending']);
        $rejected = new Order(['catalog_review_status' => 'rejected']);

        foreach ([
            [$pending, '展示订单尚未完成人工审核'],
            [$rejected, '展示订单审核未通过'],
        ] as [$order, $message]) {
            try {
                $service->ensurePaymentAllowed($order);
                $this->fail('应阻止未通过审核的展示订单支付');
            } catch (\RuntimeException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }
    }

    /**
     * 展示订单缺少履约 SKU 时必须在访问数据库前失败。
     */
    public function test_public_order_requires_fulfillment_sku(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('展示订单缺少履约 SKU');

        (new CatalogOrderService)->validateCartItems([[
            'catalog_mode' => 'public',
            'quantity'     => 1,
        ]]);
    }

    /**
     * 不受支持的商品库模式不能被当作真实订单继续处理。
     */
    public function test_order_mode_must_be_real_or_public(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('订单商品库模式无效');

        (new CatalogOrderService)->validateCartItems([[
            'catalog_mode' => 'unknown',
            'quantity'     => 1,
        ]]);
    }

    /**
     * 订单商品模型暴露阶段五所需的不可变快照字段。
     */
    public function test_order_product_contains_catalog_snapshot_fields(): void
    {
        $fillable = (new OrderProduct)->getFillable();

        $this->assertContains('catalog_mode', $fillable);
        $this->assertContains('catalog_product_id', $fillable);
        $this->assertContains('catalog_sku_id', $fillable);
        $this->assertContains('fulfillment_sku', $fillable);
        $this->assertContains('catalog_mapping_version', $fillable);
    }
}
