<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\CalculatesVat;

class Product extends Model
{
    use CalculatesVat;

    protected $fillable = [
        'category_id',
        'name_ar',
        'name_en',
        'slug',
        'description_ar',
        'description_en',
        'base_price',
        'vat',
        'is_available',
        'is_featured',
        'sku',
        'country_of_origin',
        'roast_date',
        'brewing_method',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'vat' => 'decimal:2',
        'is_available' => 'boolean',
        'is_featured' => 'boolean',
        'roast_date' => 'date',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function offers(): BelongsToMany
    {
        return $this->belongsToMany(ProductOffer::class, 'product_offer_items');
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_available', true);
    }
}