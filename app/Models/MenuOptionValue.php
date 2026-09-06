<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuOptionValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'menu_option_id',
        'name_ar',
        'name_en',
        'extra_price',
    ];

    protected $casts = [
        'extra_price' => 'decimal:2',
    ];

    public function option(): BelongsTo
    {
        return $this->belongsTo(MenuOption::class, 'menu_option_id');
    }
}
