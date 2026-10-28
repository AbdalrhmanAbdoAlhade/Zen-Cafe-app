<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomMessageLog extends Model
{
    public const CHANNEL_EMAIL    = 'email';
    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT    = 'sent';
    public const STATUS_FAILED  = 'failed';

    protected $fillable = [
        'customer_id',
        'order_id',
        'sent_by',
        'channel',
        'to_address',
        'subject',
        'body',
        'status',
        'provider_message_id',
        'error_message',
        'attempts',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}