<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'menu_item_id',
        'name_ar',
        'name_en',
        'type',
        'is_required',
    ];

    protected $casts = [
        'is_required' => 'boolean',
    ];

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(MenuOptionValue::class);
    }
}
