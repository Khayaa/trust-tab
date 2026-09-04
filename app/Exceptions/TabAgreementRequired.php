<?php

namespace App\Exceptions;

use Exception;

class TabAgreementRequired extends Exception
{
    public function userMessage(): string
    {
        return 'Agree the tab limit and payday before adding to this tab.';
    }
}
