<?php

namespace Plugin\ProductCustomization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderCustomization extends Model
{
    protected $table = 'order_product_customizations';

    protected $fillable = ['order_product_id', 'template_id', 'template_version', 'line_key', 'fields', 'values'];

    protected $casts = ['fields' => 'array', 'values' => 'array', 'template_version' => 'integer'];

    public function orderProduct(): BelongsTo
    {
        return $this->belongsTo(\Beike\Models\OrderProduct::class, 'order_product_id');
    }
}
