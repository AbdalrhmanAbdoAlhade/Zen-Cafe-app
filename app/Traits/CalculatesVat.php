<?php

namespace App\Traits;

trait CalculatesVat
{
    /**
     * vat مخزّنة كنسبة مئوية (15 = 15%).
     * نفس شكل MenuItem::priceWithVat().
     *
     * @param  float|null  $basePrice  مرّر السعر الفعلي (مثلاً سعر الـ variant)، وإلا base_price
     */
    public function priceWithVat(?float $basePrice = null): array
    {
        $price = round((float) ($basePrice ?? $this->base_price), 2);
        $vatPercent = round((float) ($this->vat ?? 0), 2);
        $vatAmount = round($price * ($vatPercent / 100), 2);

        return [
            'price' => $price,                            // بدون ضريبة
            'vat' => $vatPercent,                         // نسبة %
            'vat_amount' => $vatAmount,                   // مبلغ الضريبة
            'total_price' => round($price + $vatAmount, 2), // شامل الضريبة
        ];
    }
}
