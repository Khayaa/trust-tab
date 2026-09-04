<?php

namespace App\Exceptions;

use Exception;

class InvalidHybridSplit extends Exception
{
    public function userMessage(): string
    {
        return 'Pay some with MoMo and put the rest on the tab.';
    }
}
