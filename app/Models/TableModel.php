<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TableModel extends Model
{
    use HasFactory;

    protected $table = 'tables';

    protected $fillable = [
        'branch_id',
        'table_number',
        'qr_code_id',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function qrCode(): BelongsTo
    {
        return $this->belongsTo(QrCode::class, 'qr_code_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'table_id');
    }
}
