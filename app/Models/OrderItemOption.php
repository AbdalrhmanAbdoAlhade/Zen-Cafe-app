<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_item_id',
        'menu_option_value_id',
        'extra_price',
    ];

    protected $casts = [
        'extra_price' => 'decimal:2',
    ];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function optionValue(): BelongsTo
    {
        return $this->belongsTo(MenuOptionValue::class, 'menu_option_value_id');
    }
}
