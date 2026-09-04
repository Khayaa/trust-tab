<?php

namespace App\Exceptions;

use Exception;

class TabLimitExceeded extends Exception
{
    public function userMessage(): string
    {
        return 'That would go over the agreed tab limit.';
    }
}
