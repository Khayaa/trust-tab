<?php

namespace App\Exceptions;

use Exception;

class CheckoutAwaitingConfirmation extends Exception
{
    public function userMessage(): string
    {
        return 'Waiting for them to confirm this basket.';
    }
}
