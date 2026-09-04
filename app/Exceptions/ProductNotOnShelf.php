<?php

namespace App\Exceptions;

use Exception;

class ProductNotOnShelf extends Exception
{
    public function userMessage(): string
    {
        return 'That is off the shelf.';
    }
}
