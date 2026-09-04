<?php

namespace App\Exceptions;

use Exception;

class CheckoutAwaitingMomo extends Exception
{
    public function userMessage(): string
    {
        return 'Waiting for MoMo. This basket is frozen.';
    }
}
