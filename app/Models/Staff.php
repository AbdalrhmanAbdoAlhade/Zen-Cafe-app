<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Staff extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'staff';

    protected $fillable = [
        'branch_id',
        'name',
        'phone',
        'username',
        'password',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'password' => 'hashed',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(OrderStatusLog::class, 'changed_by_staff_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    public function scopeCashiers($query)
    {
        return $query->where('role', 'cashier');
    }

    public function scopeKitchen($query)
    {
        return $query->where('role', 'kitchen');
    }
}
