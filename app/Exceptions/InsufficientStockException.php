<?php

namespace App\Exceptions;

use Exception;

class InsufficientStockException extends Exception
{
    public function __construct(int $productVariantId, int $requested, int $available)
    {
        parent::__construct(
            "الكمية المطلوبة ({$requested}) أكبر من المتاح ({$available}) للمنتج رقم {$productVariantId}."
        );
    }
}