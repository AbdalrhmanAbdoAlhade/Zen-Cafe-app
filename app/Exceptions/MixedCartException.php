<?php

namespace App\Exceptions;

use Exception;

class MixedCartException extends Exception
{
    public function __construct()
    {
        parent::__construct(
            'لا يمكن الجمع بين منتجات المنيو ومنتجات المتجر في نفس الطلب، برجاء إتمام كل نوع طلب على حدة.'
        );
    }
}