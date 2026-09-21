<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnlinePayment extends Model
{
    public const STATUS_INITIATED     = 'initiated';
    public const STATUS_PAID          = 'paid';
    public const STATUS_FAILED        = 'failed';
    public const STATUS_REFUNDED      = 'refunded';
    public const STATUS_REFUND_FAILED = 'refund_failed';

    protected $fillable = [
        'order_id',
        'customer_id',
        'gateway',
        'gateway_order_id',
        'amount',
        'currency',
        'status',
        'session_id',
        'success_indicator',
        'transaction_id',
        'refund_transaction_id',
        'gateway_response',
        'paid_at',
        'refunded_at',
    ];

    protected $casts = [
        'amount'           => 'decimal:2',
        'gateway_response' => 'array',
        'paid_at'          => 'datetime',
        'refunded_at'      => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}