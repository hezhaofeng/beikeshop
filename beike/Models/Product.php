<?php

namespace Beike\Models;

use Beike\Models\Concerns\UsesCatalogConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Base
{
    use HasFactory;
    use SoftDeletes;
    use UsesCatalogConnection;

    protected $fillable = ['images', 'video', 'position', 'brand_id', 'tax_class_id', 'weight', 'weight_class', 'active', 'shipping', 'variables'];

    protected $casts = [
        'active'    => 'boolean',
        'variables' => 'array',
        'images'    => 'array',
    ];

    protected $appends = ['image'];

    public function categories()
    {
        return $this->belongsToMany(Category::class, ProductCategory::class)->withTimestamps();
    }

    public function productCategories()
    {
        return $this->hasMany(ProductCategory::class);
    }

    public function description()
    {
        return $this->hasOne(ProductDescription::class)->where('locale', locale());
    }

    public function descriptions()
    {
        return $this->hasMany(ProductDescription::class);
    }

    public function skus()
    {
        return $this->hasMany(ProductSku::class);
    }

    public function views()
    {
        return $this->hasMany(ProductView::class);
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class);
    }

    public function masterSku()
    {
        return $this->hasOne(ProductSku::class)->where('is_default', getDBDriver() == 'mysql' ? 1 : true);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class, 'brand_id', 'id');
    }

    public function relations()
    {
        return $this->belongsToMany(self::class, ProductRelation::class, 'product_id', 'relation_id')->withTimestamps();
    }

    public function inCurrentWishlist()
    {
        $customer   = current_customer();
        $customerId = $customer ? $customer->id : 0;
        $mode       = app(\Plugin\CyberCloak\Services\CatalogCartItemService::class)->currentMode();

        return $this->hasOne(CustomerWishlist::class)
            ->where('customer_id', $customerId)
            ->where('catalog_mode', $mode)
            ->where('catalog_product_id', $this->getKey());
    }

    public function getUrlAttribute()
    {
        $urlId   = app(\Plugin\CyberCloak\Services\CatalogRouteService::class)->urlIdForProduct($this);
        $url     = shop_route('products.show', ['product' => $urlId]);
        $filters = hook_filter('model.product.url', ['url' => $url, 'product' => $this]);

        return $filters['url'] ?? '';
    }

    public function getImageAttribute()
    {
        $images = $this->images ?? [];

        return $images[0] ?? '';
    }
}
