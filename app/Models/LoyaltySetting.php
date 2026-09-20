<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltySetting extends Model
{
protected $fillable = [
    'points_earn_rate',
    'point_redemption_value',
    'minimum_points_to_redeem',
    'points_expiry_months',      // ← جديد
    'updated_by_staff_id',
  'tier_silver_min_spent',
'tier_gold_min_spent',
];

protected $casts = [
    'points_earn_rate'         => 'decimal:2',
    'point_redemption_value'   => 'decimal:2',
  'tier_silver_min_spent'  => 'decimal:2',
'tier_gold_min_spent'   => 'decimal:2',
    'minimum_points_to_redeem' => 'integer',
    'points_expiry_months'     => 'integer',   // ← جديد
];

   public static function current(): self
{
    return static::query()->firstOrCreate(['id' => 1], [
        'points_earn_rate'         => 10.00,
        'point_redemption_value'   => 0.50,
        'minimum_points_to_redeem' => 20,
        'points_expiry_months'     => 12,
    ]);
}
    public function updatedByStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'updated_by_staff_id');
    }
}
