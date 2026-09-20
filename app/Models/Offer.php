<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Offer extends Model
{
    protected $fillable = [
        'name_ar',
        'name_en',
        'description_ar',
        'description_en',
        'is_active',
        'starts_at',
        'ends_at',
        'sort_order',
        'branch_id',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'starts_at'  => 'datetime',
        'ends_at'    => 'datetime',
        'sort_order' => 'integer',
    ];

    public function images(): HasMany
    {
        return $this->hasMany(OfferImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** مفعّل + داخل فترة العرض (لو محددة) */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    /**
     * عروض الفرع المحدد + العروض العامة (branch_id = null).
     * لو مفيش فرع: العروض العامة بس.
     */
    public function scopeForBranch($query, ?int $branchId)
    {
        return $query->where(function ($q) use ($branchId) {
            $q->whereNull('branch_id');

            if ($branchId) {
                $q->orWhere('branch_id', $branchId);
            }
        });
    }
}
