<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductStockMovement extends Model
{
    public const TYPE_SALE = 'sale';
    public const TYPE_RESTOCK = 'restock';
    public const TYPE_CANCELLATION_REFUND = 'cancellation_refund';
    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = ['product_variant_id', 'type', 'quantity', 'reference_order_id'];

    protected $casts = ['quantity' => 'integer'];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function referenceOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'reference_order_id');
    }
}