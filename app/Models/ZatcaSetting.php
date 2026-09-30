<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ZatcaSetting extends Model
{
    protected $fillable = [
        'seller_name',
        'vat_number',
        'cr_number',
        'address_ar',
        'is_enabled',
        'last_invoice_seq',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'last_invoice_seq' => 'integer',
    ];

    public static function current(): ?self
    {
        return static::query()->first();
    }

    public static function currentOrFail(): self
    {
        $setting = static::current();

        if (! $setting) {
            throw new \RuntimeException('إعدادات ZATCA غير معرّفة. أضف بيانات الشركة من لوحة الأدمن.');
        }

        return $setting;
    }

    public function isReady(): bool
    {
        return $this->is_enabled
            && filled($this->seller_name)
            && filled($this->vat_number)
            && strlen((string) $this->vat_number) === 15;
    }

    public function nextInvoiceNumber(): string
    {
        return DB::transaction(function () {
            $row = static::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();
            $row->last_invoice_seq = (int) $row->last_invoice_seq + 1;
            $row->save();

            return 'INV-'.str_pad((string) $row->last_invoice_seq, 8, '0', STR_PAD_LEFT);
        });
    }
}