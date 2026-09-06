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
        'updated_by_staff_id',
    ];

    protected $casts = [
        'points_earn_rate' => 'decimal:2',
        'point_redemption_value' => 'decimal:2',
        'minimum_points_to_redeem' => 'integer',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1], [
            'points_earn_rate' => 10.00,
            'point_redemption_value' => 0.50,
            'minimum_points_to_redeem' => 20,
        ]);
    }

    public function updatedByStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'updated_by_staff_id');
    }
}
