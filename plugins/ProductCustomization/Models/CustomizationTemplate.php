<?php

namespace Plugin\ProductCustomization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomizationTemplate extends Model
{
    protected $table = 'product_customization_templates';

    protected $fillable = ['name', 'version', 'priority', 'active'];

    protected $casts = [
        'name'     => 'array',
        'version'  => 'integer',
        'priority' => 'integer',
        'active'   => 'boolean',
    ];

    public function fields(): HasMany
    {
        return $this->hasMany(CustomizationField::class, 'template_id')->orderBy('sort')->orderBy('id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            \Beike\Models\Category::class,
            'product_customization_template_categories',
            'template_id',
            'category_id'
        );
    }
}
