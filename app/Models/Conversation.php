<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    public const STATUS_BOT           = 'bot';
    public const STATUS_WAITING_AGENT = 'waiting_agent';
    public const STATUS_WITH_AGENT    = 'with_agent';
    public const STATUS_CLOSED        = 'closed';

    protected $fillable = [
        'customer_id',
        'phone',
        'guest_name',
        'branch_id',
        'channel',
        'status',
        'assigned_staff_id',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'assigned_staff_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('id');
    }
}