<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FaqEntry extends Model
{
    protected $fillable = [
        'intent_key',
        'keywords',
        'reply_ar',
        'reply_en',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'keywords'  => 'array',
        'is_active' => 'boolean',
    ];
}