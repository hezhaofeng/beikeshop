<?php

namespace Beike\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CustomerWishlist extends Base
{
    use HasFactory;

    /**
     * 收藏表属于主库，不能继承展示商品模型的 catalog_public 连接。
     */
    public function getConnectionName(): ?string
    {
        if ($this->connection !== null) {
            return parent::getConnectionName();
        }

        return (string) config('database.default', 'mysql');
    }

    protected $fillable = ['customer_id', 'product_id', 'catalog_mode', 'catalog_product_id'];

    protected $casts = [
        'catalog_product_id' => 'integer',
    ];

    public function product(): HasOne
    {
        return $this->hasOne(Product::class, 'id', 'product_id');
    }
}
