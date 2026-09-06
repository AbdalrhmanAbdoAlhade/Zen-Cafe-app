<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QrCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'table_id',
        'token',
        'type',
        'radius_override',
        'manual_access_code',
        'is_active',
        'expires_at',
    ];

    protected $hidden = [
        'manual_access_code',
    ];

    protected $casts = [
        'radius_override' => 'integer',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(TableModel::class, 'table_id');
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(MenuAccessLog::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * نطاق الجيوفنسينج الفعلي: الـ override الخاص بالـ QR ده لو موجود، وإلا default الفرع.
     */
    public function effectiveRadius(): int
    {
        return $this->radius_override ?? $this->branch->default_radius_meters;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }
}
