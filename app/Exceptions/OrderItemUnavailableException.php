<?php

namespace App\Exceptions;

use Exception;

class OrderItemUnavailableException extends Exception
{
    public function __construct(int $menuItemId)
    {
        parent::__construct("الصنف رقم [{$menuItemId}] غير متاح حاليًا في هذا الفرع.");
    }
}
