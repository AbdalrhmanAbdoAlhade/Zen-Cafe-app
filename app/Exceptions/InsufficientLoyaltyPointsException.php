<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class InsufficientLoyaltyPointsException extends Exception
{
    public function __construct(string $message = 'رصيد النقاط غير كافٍ أو أن الاستبدال غير متاح لهذا الطلب.')
    {
        parent::__construct($message);
    }

    public function render($request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
