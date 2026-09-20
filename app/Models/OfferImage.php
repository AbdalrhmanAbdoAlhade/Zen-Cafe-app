<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfferImage extends Model
{
    protected $fillable = ['offer_id', 'image_path', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    protected $appends = ['url'];

    public function getUrlAttribute(): string
    {
        return url('/storage/' . $this->image_path);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
