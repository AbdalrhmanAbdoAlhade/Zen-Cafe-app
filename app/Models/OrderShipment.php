<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderShipment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_DELIVERED = 'delivered';

    protected $fillable = [
        'order_id',
        'recipient_name',
        'recipient_phone',
        'city',
        'address_line',
        'building',
        'landmark',
        'shipping_fee',
        'status',
        'tracking_number',
        'shipped_at',
        'delivered_at',
    ];

    protected $casts = [
        'shipping_fee' => 'decimal:2',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function markShipped(?string $trackingNumber = null): void
    {
        $this->update([
            'status' => self::STATUS_SHIPPED,
            'tracking_number' => $trackingNumber ?? $this->tracking_number,
            'shipped_at' => now(),
        ]);
    }

    public function markDelivered(): void
    {
        $this->update([
            'status' => self::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);
    }
}