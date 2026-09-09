<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_PREPARING = 'preparing';
    public const STATUS_READY = 'ready';
    public const STATUS_SERVED = 'served';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'branch_id',
        'table_id',
        'qr_code_id',
        'customer_id',
        'order_type',
        'status',
        'total_amount',
        'estimated_preparation_minutes',
        'estimated_ready_at',
        'redeemed_points',
        'redeemed_amount',
        'earned_points',
        'notes',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'redeemed_points' => 'integer',
        'redeemed_amount' => 'decimal:2',
        'earned_points' => 'integer',
        'estimated_preparation_minutes' => 'integer',
        'estimated_ready_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(TableModel::class, 'table_id');
    }

    public function qrCode(): BelongsTo
    {
        return $this->belongsTo(QrCode::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(OrderStatusLog::class)->orderBy('created_at');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(OrderPayment::class);
    }

    public function loyaltyTransactions(): HasMany
    {
        return $this->hasMany(LoyaltyPointsTransaction::class);
    }

    public function payableAmount(): float
    {
        return max(0, round((float) $this->total_amount - (float) $this->redeemed_amount, 2));
    }

    /**
     * خريطة الانتقالات المسموحة بين الحالات - يستخدمها OrderStatusService.
     */
    public static function allowedTransitions(): array
    {
        return [
            self::STATUS_PENDING => [self::STATUS_ACCEPTED, self::STATUS_REJECTED],
            self::STATUS_ACCEPTED => [self::STATUS_PREPARING, self::STATUS_CANCELLED],
            self::STATUS_PREPARING => [self::STATUS_READY, self::STATUS_CANCELLED],
            self::STATUS_READY => [self::STATUS_SERVED],
            self::STATUS_SERVED => [self::STATUS_PAID],
            self::STATUS_REJECTED => [],
            self::STATUS_PAID => [],
            self::STATUS_CANCELLED => [],
        ];
    }
}
