<?php

namespace Beike\Models;

use Beike\Models\Concerns\UsesCatalogConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProductDescription extends Base
{
    use HasFactory;
    use UsesCatalogConnection;

    protected $fillable = ['locale', 'name', 'content', 'meta_title', 'meta_description', 'meta_keywords'];
}
