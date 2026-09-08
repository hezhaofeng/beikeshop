<?php

namespace Plugin\Meilisearch\Models;

use Illuminate\Database\Eloquent\Model;

class IndexState extends Model
{
    protected $table = 'meilisearch_index_states';

    protected $fillable = [
        'catalog',
        'locale',
        'active_index',
        'last_updated_at',
        'last_product_id',
    ];

    protected $casts = [
        'last_updated_at' => 'datetime',
        'last_product_id' => 'integer',
    ];
}
