<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Coupon extends Model
{
    public const TYPE_PERCENTAGE    = 'percentage';
    public const TYPE_FIXED         = 'fixed';
    public const TYPE_BUY_X_GET_Y   = 'buy_x_get_y';
    public const TYPE_FREE_SHIPPING = 'free_shipping';

    protected $fillable = [
        'code',
        'name_ar',
        'name_en',
        'description_ar',
        'description_en',
        'type',
        'discount_value',
        'max_discount_amount',
        'buy_quantity',
        'get_quantity',
        'get_discount_type',
        'get_discount_value',
        'min_order_amount',
        'first_order_only',
        'is_stackable',
        'exclude_discounted_items',
        'starts_at',
        'ends_at',
        'active_hours',
        'usage_limit',
        'usage_limit_per_customer',
        'used_count',
        'channels',
        'is_active',
    ];

    protected $casts = [
        'discount_value'           => 'decimal:2',
        'max_discount_amount'      => 'decimal:2',
        'get_discount_value'       => 'decimal:2',
        'min_order_amount'         => 'decimal:2',
        'first_order_only'         => 'boolean',
        'is_stackable'             => 'boolean',
        'exclude_discounted_items' => 'boolean',
        'starts_at'                => 'datetime',
        'ends_at'                  => 'datetime',
        'active_hours'             => 'array',
        'channels'                 => 'array',
        'is_active'                => 'boolean',
        'buy_quantity'             => 'integer',
        'get_quantity'             => 'integer',
        'usage_limit'              => 'integer',
        'usage_limit_per_customer' => 'integer',
        'used_count'               => 'integer',
    ];

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'coupon_branch');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'coupon_product');
    }

    public function menuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class, 'coupon_menu_item');
    }

    public function productCategories(): BelongsToMany
    {
        return $this->belongsToMany(ProductCategory::class, 'coupon_product_category');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    public function scopeByCode($query, string $code)
    {
        return $query->whereRaw('UPPER(code) = ?', [strtoupper(trim($code))]);
    }

    public function isCurrentlyActive(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $now = now();

        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }

        if ($this->ends_at && $now->gt($this->ends_at)) {
            return false;
        }

        return $this->isWithinActiveHours($now);
    }

    public function isWithinActiveHours(?Carbon $now = null): bool
    {
        if (empty($this->active_hours)) {
            return true;
        }

        $now = $now ?? now();
        $dayOfWeek = $now->dayOfWeek;
        $currentTime = $now->format('H:i');

        foreach ($this->active_hours as $slot) {
            if ((int) ($slot['day'] ?? -1) !== $dayOfWeek) {
                continue;
            }

            $from = $slot['from'] ?? '00:00';
            $to   = $slot['to'] ?? '23:59';

            if ($currentTime >= $from && $currentTime <= $to) {
                return true;
            }
        }

        return false;
    }

    public function hasRemainingUsage(): bool
    {
        if ($this->usage_limit === null) {
            return true;
        }

        return $this->used_count < $this->usage_limit;
    }

    public function appliesToBranch(int $branchId): bool
    {
        if ($this->branches()->count() === 0) {
            return true;
        }

        return $this->branches()->where('branches.id', $branchId)->exists();
    }

    public function appliesToChannel(string $channel): bool
    {
        if (empty($this->channels)) {
            return true;
        }

        return in_array($channel, $this->channels, true);
    }
}
