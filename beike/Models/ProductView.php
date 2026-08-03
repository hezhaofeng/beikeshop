<?php

/**
 * ProductView.php
 *
 * @copyright  2023 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2023-11-29 20:22:18
 * @modified   2023-11-29 20:22:18
 */

namespace Beike\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductView extends Base
{
    use HasFactory;

    /**
     * 浏览记录属于主库，不能继承展示商品模型的 catalog_public 连接。
     */
    public function getConnectionName(): ?string
    {
        if ($this->connection !== null) {
            return parent::getConnectionName();
        }

        return (string) config('database.default', 'mysql');
    }

    protected $fillable = ['product_id', 'customer_id', 'ip', 'session_id', 'referer', 'user_agent'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
