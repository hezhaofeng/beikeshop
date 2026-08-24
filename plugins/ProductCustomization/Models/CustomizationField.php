<?php

namespace Plugin\ProductCustomization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomizationField extends Model
{
    protected $table = 'product_customization_fields';

    protected $fillable = [
        'template_id', 'field_key', 'label', 'type', 'placeholder', 'options',
        'max_length', 'required', 'active', 'sort',
    ];

    protected $casts = [
        'label'       => 'array',
        'placeholder' => 'array',
        'options'     => 'array',
        'max_length'  => 'integer',
        'required'    => 'boolean',
        'active'      => 'boolean',
        'sort'        => 'integer',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(CustomizationTemplate::class, 'template_id');
    }
}
