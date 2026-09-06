<?php

namespace App\Exceptions;

use Exception;

class QrCodeNotAccessibleException extends Exception
{
    public function __construct(string $message = 'رمز QR غير صالح أو منتهي الصلاحية.')
    {
        parent::__construct($message);
    }
}
