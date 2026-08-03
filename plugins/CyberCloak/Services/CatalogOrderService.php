<?php

namespace Plugin\CyberCloak\Services;

use Beike\Models\Order;
use Beike\Models\OrderProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class CatalogOrderService
{
    /**
     * 展示订单只有审核通过后才能进入支付成功状态，真实订单保持原有流程。
     */
    public function ensurePaymentAllowed(Order $order): void
    {
        $reviewStatus = (string) ($order->getAttribute('catalog_review_status') ?: 'not_required');
        if ($reviewStatus === 'pending') {
            throw new RuntimeException('展示订单尚未完成人工审核');
        }
        if ($reviewStatus === 'rejected') {
            throw new RuntimeException('展示订单审核未通过');
        }
    }

    /**
     * 校验订单商品快照和实际履约库存，创建订单前重新读取库存。
     *
     * @param array<int,array<string,mixed>> $cartItems
     */
    public function validateCartItems(array $cartItems): void
    {
        foreach ($cartItems as $cartItem) {
            $item   = $this->normalizeItem($cartItem);
            $target = $this->resolveInventoryTarget($item);
            $stock  = DB::connection($target['connection'])
                ->table('product_skus')
                ->where('id', $target['id'])
                ->value('quantity');

            if ($stock === null || (int) $stock < $item['quantity']) {
                throw new RuntimeException("履约 SKU {$target['sku']} 库存不足");
            }
        }
    }

    /**
     * 支付成功时按订单快照扣减履约库存，使用条件更新防止并发超卖。
     */
    public function decrement(Order $order): void
    {
        $order->loadMissing('orderProducts');
        $changed = [];

        try {
            foreach ($order->orderProducts as $orderProduct) {
                $item    = $this->normalizeItem($orderProduct);
                $target  = $this->resolveInventoryTarget($item);
                $updated = DB::connection($target['connection'])
                    ->table('product_skus')
                    ->where('id', $target['id'])
                    ->where('quantity', '>=', $item['quantity'])
                    ->decrement('quantity', $item['quantity']);

                if ($updated !== 1) {
                    throw new RuntimeException("履约 SKU {$target['sku']} 库存不足，订单 {$order->number} 未完成支付");
                }
                $changed[] = ['target' => $target, 'quantity' => $item['quantity']];

                hook_action('service.state_machine.sub_stock.after', [
                    'order_product'  => $orderProduct,
                    'order_number'   => $order->number,
                    'connection'     => $target['connection'],
                    'sku_id'         => $target['id'],
                ]);
            }
        } catch (\Throwable $exception) {
            // 主库事务无法覆盖展示库连接，跨库失败时主动补回已经扣减的明细。
            $this->restoreChangedQuantities($changed);

            throw $exception;
        }
    }

    /**
     * 退款或取消已支付订单时恢复原订单履约库存。
     */
    public function restore(Order $order): void
    {
        $order->loadMissing('orderProducts');
        $changed = [];

        try {
            foreach ($order->orderProducts as $orderProduct) {
                $item   = $this->normalizeItem($orderProduct);
                $target = $this->resolveInventoryTarget($item);
                DB::connection($target['connection'])
                    ->table('product_skus')
                    ->where('id', $target['id'])
                    ->increment('quantity', $item['quantity']);
                $changed[] = ['target' => $target, 'quantity' => $item['quantity']];

                hook_action('service.state_machine.revert_stock.after', [
                    'order_product'  => $orderProduct,
                    'order_number'   => $order->number,
                    'connection'     => $target['connection'],
                    'sku_id'         => $target['id'],
                ]);
            }
        } catch (\Throwable $exception) {
            // 恢复过程自身失败时撤销已经恢复的数量，保持跨库操作可重试。
            $this->decrementChangedQuantities($changed);

            throw $exception;
        }
    }

    /**
     * 支付成功后更新对应商品库的销量；展示库没有该字段时跳过统计但不影响订单。
     */
    public function incrementSales(Order $order): void
    {
        $order->loadMissing('orderProducts');
        foreach ($order->orderProducts as $orderProduct) {
            $item       = $this->normalizeItem($orderProduct);
            $connection = $item['mode'] === StoreContext::PUBLIC ? $this->publicConnection() : $this->realConnection();
            $productId  = $item['catalog_product_id'];
            if ($productId < 1 || ! Schema::connection($connection)->hasColumn('products', 'sales')) {
                continue;
            }

            DB::connection($connection)->table('products')->where('id', $productId)->increment('sales', $item['quantity']);
        }
    }

    /**
     * 获取当前已发布的展示 SKU 映射版本，用于写入订单审计快照。
     */
    public function mappingVersion(string $mode = null): ?string
    {
        if (($mode ?: StoreContext::REAL) !== StoreContext::PUBLIC) {
            return null;
        }

        try {
            return DB::connection($this->realConnection())
                ->table('catalog_mapping_versions')
                ->where('status', 'published')
                ->orderByDesc('published_at')
                ->value('version');
        } catch (\Throwable) {
            // 阶段迁移尚未执行时返回空版本，由上层继续按缺少履约映射处理。
            return null;
        }
    }

    /**
     * 将购物车数组或订单模型统一为库存服务使用的快照字段。
     *
     * @param array<string,mixed>|OrderProduct $item
     * @return array{mode:string,catalog_product_id:int,catalog_sku_id:int,product_sku:string,fulfillment_sku:string,quantity:int}
     */
    private function normalizeItem(array|OrderProduct $item): array
    {
        $read = static fn (string $key, mixed $default = null): mixed => is_array($item) ? ($item[$key] ?? $default) : ($item->{$key} ?? $default);
        $mode = (string) $read('catalog_mode', StoreContext::REAL);
        if (! in_array($mode, [StoreContext::REAL, StoreContext::PUBLIC], true)) {
            throw new RuntimeException('订单商品库模式无效');
        }

        $fulfillmentSku = trim((string) $read('fulfillment_sku', ''));
        if ($mode === StoreContext::PUBLIC && $fulfillmentSku === '') {
            throw new RuntimeException('展示订单缺少履约 SKU');
        }

        $productSku = trim((string) $read('product_sku', ''));

        return [
            'mode'               => $mode,
            'catalog_product_id' => (int) $read('catalog_product_id', $read('product_id', 0)),
            'catalog_sku_id'     => (int) $read('catalog_sku_id', $read('sku_id', 0)),
            'product_sku'        => $productSku,
            'fulfillment_sku'    => $fulfillmentSku ?: $productSku,
            'quantity'           => (int) $read('quantity', 0),
        ];
    }

    /**
     * 根据订单快照解析实际库存连接和 SKU ID，展示订单优先使用映射到主库的履约 SKU。
     *
     * @param array{mode:string,catalog_sku_id:int,product_sku:string,fulfillment_sku:string,quantity:int} $item
     * @return array{connection:string,id:int,sku:string}
     */
    private function resolveInventoryTarget(array $item): array
    {
        if ($item['quantity'] < 1) {
            throw new RuntimeException('订单商品数量无效');
        }

        if ($item['mode'] === StoreContext::REAL) {
            $query = DB::connection($this->realConnection())->table('product_skus');
            if ($item['catalog_sku_id'] > 0) {
                $query->where('id', $item['catalog_sku_id']);
            } else {
                $query->where('sku', $item['fulfillment_sku']);
            }
            if ($item['fulfillment_sku'] !== '') {
                $query->where('sku', $item['fulfillment_sku']);
            }
            $row = $query->first(['id', 'sku']);
            if (! $row) {
                throw new RuntimeException("真实履约 SKU {$item['fulfillment_sku']} 不存在");
            }

            return ['connection' => $this->realConnection(), 'id' => (int) $row->id, 'sku' => (string) $row->sku];
        }

        $realRow = DB::connection($this->realConnection())
            ->table('product_skus')
            ->where('sku', $item['fulfillment_sku'])
            ->first(['id', 'sku']);
        if ($realRow) {
            return ['connection' => $this->realConnection(), 'id' => (int) $realRow->id, 'sku' => (string) $realRow->sku];
        }

        $publicRow = DB::connection($this->publicConnection())
            ->table('product_skus')
            ->where('id', $item['catalog_sku_id'])
            ->where('sku', $item['fulfillment_sku'])
            ->first(['id', 'sku']);
        if (! $publicRow) {
            throw new RuntimeException("展示订单履约 SKU {$item['fulfillment_sku']} 不存在");
        }

        return ['connection' => $this->publicConnection(), 'id' => (int) $publicRow->id, 'sku' => (string) $publicRow->sku];
    }

    /**
     * 补回一次扣减操作，供跨库扣减失败时回滚已经完成的明细。
     *
     * @param array<int,array{target:array{connection:string,id:int,sku:string},quantity:int}> $changed
     */
    private function restoreChangedQuantities(array $changed): void
    {
        foreach (array_reverse($changed) as $change) {
            DB::connection($change['target']['connection'])
                ->table('product_skus')
                ->where('id', $change['target']['id'])
                ->increment('quantity', $change['quantity']);
        }
    }

    /**
     * 撤销一次库存恢复操作，避免部分恢复造成重复可售库存。
     *
     * @param array<int,array{target:array{connection:string,id:int,sku:string},quantity:int}> $changed
     */
    private function decrementChangedQuantities(array $changed): void
    {
        foreach (array_reverse($changed) as $change) {
            DB::connection($change['target']['connection'])
                ->table('product_skus')
                ->where('id', $change['target']['id'])
                ->decrement('quantity', $change['quantity']);
        }
    }

    /**
     * 获取真实商品库连接名。
     */
    private function realConnection(): string
    {
        return (string) config('cyber_cloak.connections.real', 'mysql');
    }

    /**
     * 获取展示商品库连接名。
     */
    private function publicConnection(): string
    {
        return (string) config('cyber_cloak.connections.public', 'catalog_public');
    }
}
