<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class ReorderUnavailableException extends Exception
{
    public function __construct()
    {
        parent::__construct('كل أصناف هذا الطلب لم تعد متاحة حاليًا، برجاء اختيار طلب آخر.');
    }

    public function render($request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}