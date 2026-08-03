<?php

namespace Beike\Models;

use Beike\Models\Concerns\UsesCatalogConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryDescription extends Base
{
    use HasFactory;
    use UsesCatalogConnection;

    protected $fillable = [
        'category_id',
        'locale',
        'name',
        'content',
        'meta_title',
        'meta_description',
        'meta_keywords',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
