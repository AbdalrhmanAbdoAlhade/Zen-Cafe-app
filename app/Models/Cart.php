<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_ABANDONED = 'abandoned';
    public const STATUS_RECOVERED = 'recovered';

    public const TYPE_MENU = 'menu';
    public const TYPE_STORE = 'store';

    public const STAGE_BROWSING = 'browsing';
    public const STAGE_ITEMS_ADDED = 'items_added';
    public const STAGE_CHECKOUT_STARTED = 'checkout_started';
    public const STAGE_PAYMENT_FAILED = 'payment_failed';

    protected $fillable = [
        'customer_id', 'branch_id', 'session_token', 'cart_type', 'status',
        'abandon_stage', 'last_activity_at', 'abandoned_at', 'recovered_at',
        'converted_order_id', 'estimated_total', 'items_count', 'meta',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
        'abandoned_at' => 'datetime',
        'recovered_at' => 'datetime',
        'estimated_total' => 'decimal:2',
        'items_count' => 'integer',
        'meta' => 'array',
    ];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function items(): HasMany { return $this->hasMany(CartItem::class); }
    public function convertedOrder(): BelongsTo { return $this->belongsTo(Order::class, 'converted_order_id'); }

   public function recalculateTotals(): void
{
    // نجيب العناصر من الـ DB دايماً، مش من الـ relation المخزّنة
    $items = $this->items()->get();

    $count = (int) $items->sum('quantity');
    $total = round(
        (float) $items->sum(fn (CartItem $i) => (float) $i->unit_price * (int) $i->quantity),
        2
    );

    $this->update([
        'items_count' => $count,
        'estimated_total' => $total,
        'last_activity_at' => now(),
        'abandon_stage' => $count > 0 ? self::STAGE_ITEMS_ADDED : self::STAGE_BROWSING,
    ]);

    // نحدّث العلاقة المخزّنة بعد الحساب
    $this->setRelation('items', $items);
}

    public function markAbandoned(?string $stage = null): void
    {
        $this->update([
            'status' => self::STATUS_ABANDONED,
            'abandoned_at' => now(),
            'abandon_stage' => $stage ?? $this->abandon_stage ?? self::STAGE_ITEMS_ADDED,
        ]);
    }

    public function markConverted(Order $order): void
    {
        $this->update([
            'status' => self::STATUS_CONVERTED,
            'converted_order_id' => $order->id,
            'last_activity_at' => now(),
        ]);
    }

    public function markRecovered(): void
    {
        $this->update([
            'status' => self::STATUS_RECOVERED,
            'recovered_at' => now(),
            'last_activity_at' => now(),
        ]);
    }
}