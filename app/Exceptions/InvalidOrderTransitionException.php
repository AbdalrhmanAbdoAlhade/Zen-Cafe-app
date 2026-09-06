<?php

namespace App\Exceptions;

use Exception;

class InvalidOrderTransitionException extends Exception
{
    public function __construct(string $from, string $to)
    {
        parent::__construct("لا يمكن تغيير حالة الطلب من [{$from}] إلى [{$to}].");
    }
}
