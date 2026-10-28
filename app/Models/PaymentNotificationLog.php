<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentNotificationLog extends Model
{
    public const CHANNEL_EMAIL    = 'email';
    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT    = 'sent';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'online_payment_id',
        'order_id',
        'customer_id',
        'channel',
        'status',
        'skip_reason',
        'error_message',
        'provider_message_id',
        'idempotency_key',
        'attempts',
        'sent_at',
        'last_attempt_at',
    ];

    protected $casts = [
        'sent_at'         => 'datetime',
        'last_attempt_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(OnlinePayment::class, 'online_payment_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function isSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }
}