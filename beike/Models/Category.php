<?php

namespace Beike\Models;

use Beike\Models\Concerns\UsesCatalogConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Category extends Base
{
    use HasFactory;
    use UsesCatalogConnection;

    protected $fillable = [
        'parent_id', 'position', 'image', 'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function activeChildren(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->where('active', true);
    }

    public function descriptions(): HasMany
    {
        return $this->hasMany(CategoryDescription::class);
    }

    public function description(): HasOne
    {
        return $this->hasOne(CategoryDescription::class)->where('locale', locale());
    }

    public function paths(): HasMany
    {
        return $this->hasMany(CategoryPath::class);
    }

    public function productCategories(): HasMany
    {
        return $this->hasMany(ProductCategory::class);
    }

    public function getUrlAttribute()
    {
        $urlId   = app(\Plugin\CyberCloak\Services\CatalogRouteService::class)->urlIdForCategory($this);
        // 路由表存在但分类没有反向映射时不生成 categories/0，避免前台树进入不存在页面。
        $url     = $urlId > 0 ? shop_route('categories.show', ['category' => $urlId]) : '';
        $filters = hook_filter('model.category.url', ['url' => $url, 'category' => $this]);

        return $filters['url'] ?? '';
    }

    // 获取所有子孙分类 ID（避免递归选择自己作为父级）
    public function getDescendantIds(): array
    {
        $ids = [];

        foreach ($this->children as $child) {
            $ids[] = $child->id;
            $ids   = array_merge($ids, $child->getDescendantIds());
        }

        return $ids;
    }
}
