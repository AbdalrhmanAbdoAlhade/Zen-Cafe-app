<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'menu_item_id',
        'product_variant_id',
        'quantity',
        'unit_price',
        'preparation_time_minutes',
        'vat',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'preparation_time_minutes' => 'integer',
        'vat' => 'decimal:2',
    ];

    /* ============================================================
     |  Relations
     ============================================================ */

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(OrderItemOption::class);
    }

    /* ============================================================
     |  Pricing (vat = نسبة مئوية مخزّنة وقت الطلب)
     ============================================================ */

    /**
     * مجموع الإضافات على السطر.
     */
    public function optionsTotal(): float
    {
        return round((float) $this->options->sum('extra_price'), 2);
    }

    /**
     * الأساس قبل الضريبة: (سعر الوحدة + الإضافات) × الكمية.
     */
    public function lineBaseAmount(): float
    {
        return round(
            ((float) $this->unit_price + $this->optionsTotal()) * (int) $this->quantity,
            2
        );
    }

    /**
     * مبلغ الضريبة على السطر.
     * vat مخزّنة كنسبة % (مثلاً 15 = 15%).
     */
    public function lineVatAmount(): float
    {
        $percent = (float) ($this->vat ?? 0);

        return round($this->lineBaseAmount() * ($percent / 100), 2);
    }

    /**
     * إجمالي السطر شامل الضريبة.
     */
    public function lineTotal(): float
    {
        return round($this->lineBaseAmount() + $this->lineVatAmount(), 2);
    }

    /**
     * تفصيل كامل للسطر (للـ API / serialize).
     */
    public function pricingBreakdown(): array
    {
        $base = $this->lineBaseAmount();
        $vatAmount = $this->lineVatAmount();

        return [
            'unit_price' => round((float) $this->unit_price, 2),
            'options_total' => $this->optionsTotal(),
            'quantity' => (int) $this->quantity,
            'vat' => round((float) ($this->vat ?? 0), 2),
            'line_base' => $base,
            'vat_amount' => $vatAmount,
            'line_total' => round($base + $vatAmount, 2),
        ];
    }
}