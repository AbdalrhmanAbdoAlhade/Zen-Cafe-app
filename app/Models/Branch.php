<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'name_ar',
        'name_en',
        'address_ar',      // ← جديد
        'address_en',      // ← جديد
        'lat',
        'lng',
        'default_radius_meters',
        'is_active',
        'is_main',
        'is_online_paused',
        'pause_reason',
        'paused_at',
        'logo',
        'social_links',
        'current_prep_offset_minutes',
        'prep_offset_updated_at',
    ];

    protected $casts = [
        'lat'                   => 'decimal:7',
        'lng'                   => 'decimal:7',
        'default_radius_meters' => 'integer',
        'is_active'             => 'boolean',
        'is_main'               => 'boolean',
        'is_online_paused'      => 'boolean',
        'paused_at'             => 'datetime',
        'social_links'          => 'array',
        'current_prep_offset_minutes' => 'integer',
        'prep_offset_updated_at' => 'datetime',
    ];

    /* ============================================================
     |  Relations
     ============================================================ */

    public function qrCodes(): HasMany
    {
        return $this->hasMany(QrCode::class);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(TableModel::class, 'branch_id');
    }

    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function menuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class, 'menu_item_branch')
            ->withPivot(['price_override', 'is_available', 'is_featured'])
            ->withTimestamps();
    }

    /* ============================================================
     |  Scopes
     ============================================================ */

    public function scopeMain($query)
    {
        return $query->where('is_main', true);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOnlinePaused($query)
    {
        return $query->where('is_online_paused', true);
    }
}