<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name_ar',
        'name_en',
        'description_ar',
        'description_en',
        'image',
        'base_price',
        'is_available',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'is_available' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'category_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(MenuOption::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'menu_item_branch')
            ->withPivot(['price_override', 'is_available', 'is_featured'])
            ->withTimestamps();
    }

    /**
     * السعر الفعلي للصنف في فرع معيّن (price_override لو موجود، وإلا base_price).
     */
    public function priceForBranch(Branch $branch): float
    {
        $pivot = $this->branches->firstWhere('id', $branch->id)?->pivot;

        return (float) ($pivot?->price_override ?? $this->base_price);
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_available', true);
    }
}
