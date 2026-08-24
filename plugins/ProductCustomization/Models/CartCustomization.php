<?php

namespace Plugin\ProductCustomization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartCustomization extends Model
{
    protected $table = 'cart_product_customizations';

    protected $fillable = ['cart_product_id', 'template_id', 'template_version', 'line_key', 'fields', 'values'];

    protected $casts = ['fields' => 'array', 'values' => 'array', 'template_version' => 'integer'];

    public function cartProduct(): BelongsTo
    {
        return $this->belongsTo(\Beike\Models\CartProduct::class, 'cart_product_id');
    }
}
