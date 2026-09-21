<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyPointsTransaction extends Model
{
    public const TYPE_EARN   = 'earn';
    public const TYPE_REDEEM = 'redeem';
    public const TYPE_REFUND = 'refund';
    public const TYPE_REVOKE = 'revoke';
    public const TYPE_EXPIRE = 'expire';

    /** الأنواع اللي بتضيف نقاط قابلة للاستخدام (FIFO) */
    public const SPENDABLE_TYPES = [self::TYPE_EARN, self::TYPE_REFUND];

    protected $fillable = [
        'customer_id',
        'order_id',
        'type',
        'points',
        'remaining_points',
        'balance_after',
        'description',
        'expires_at',
    ];

    protected $casts = [
        'points'           => 'integer',
        'remaining_points' => 'integer',
        'balance_after'    => 'integer',
        'expires_at'       => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}