<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyPointsTransaction extends Model
{
    public const TYPE_EARN = 'earn';
    public const TYPE_REDEEM = 'redeem';
    public const TYPE_REFUND = 'refund';

    protected $fillable = [
        'customer_id',
        'order_id',
        'type',
        'points',
        'balance_after',
        'description',
    ];

    protected $casts = [
        'points' => 'integer',
        'balance_after' => 'integer',
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
