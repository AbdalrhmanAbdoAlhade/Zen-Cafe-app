<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $fillable = [
        'cart_id', 'menu_item_id', 'product_variant_id',
        'quantity', 'unit_price', 'options', 'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'options' => 'array',
    ];

    public function cart(): BelongsTo { return $this->belongsTo(Cart::class); }
    public function menuItem(): BelongsTo { return $this->belongsTo(MenuItem::class); }
    public function productVariant(): BelongsTo { return $this->belongsTo(ProductVariant::class); }

    public function lineTotal(): float
    {
        return round((float) $this->unit_price * (int) $this->quantity, 2);
    }
}