<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStatusLog extends Model
{
    use HasFactory;

    public $timestamps = false; // عندنا created_at بس (يتظبط في الـ Migration)

    protected $fillable = [
        'order_id',
        'status',
        'changed_by_staff_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'changed_by_staff_id');
    }
}
