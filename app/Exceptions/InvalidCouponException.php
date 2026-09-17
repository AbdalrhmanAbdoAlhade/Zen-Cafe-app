<?php

namespace App\Exceptions;

use Exception;

class InvalidCouponException extends Exception
{
    public function __construct(string $message = 'الكوبون غير صالح.', int $code = 422)
    {
        parent::__construct($message, $code);
    }

    public function render()
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error'   => 'invalid_coupon',
        ], $this->getCode() ?: 422);
    }
}