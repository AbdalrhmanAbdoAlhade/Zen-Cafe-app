<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class InsufficientLoyaltyPointsException extends Exception
{
    public function __construct(
        string $message = 'رصيد النقاط غير كافٍ أو أن الاستبدال غير متاح لهذا الطلب.'
    ) {
        parent::__construct($message);
    }

    /**
     * لا نسجّل الاستثناء في اللوج لأنه خطأ متوقع من المستخدم.
     */
    public function report(): bool
    {
        return false;
    }

    /**
     * تحويل الاستثناء لاستجابة JSON واضحة للعميل (422).
     */
    public function render($request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'errors'  => [
                'points' => [$this->getMessage()],
            ],
        ], 422);
    }
}