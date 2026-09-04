<?php

namespace App\Exceptions;

use Exception;

class EmptyBasket extends Exception
{
    public function userMessage(): string
    {
        return 'Add something before you collect.';
    }
}
