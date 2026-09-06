<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuAccessLog extends Model
{
    use HasFactory;

    public $timestamps = true;

    protected $fillable = [
        'qr_code_id',
        'ip',
        'lat',
        'lng',
        'distance_from_branch',
        'access_method',
        'allowed',
    ];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'distance_from_branch' => 'decimal:2',
        'allowed' => 'boolean',
    ];

    public function qrCode(): BelongsTo
    {
        return $this->belongsTo(QrCode::class);
    }
}
