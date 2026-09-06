<?php

namespace App\Exceptions;

use Exception;

class PaymentAmountMismatchException extends Exception
{
    public function __construct(float $expected, float $given)
    {
        parent::__construct("المبلغ المُدخل ({$given}) لا يطابق إجمالي الطلب ({$expected}).");
    }
}
