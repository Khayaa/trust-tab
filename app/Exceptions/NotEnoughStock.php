<?php

namespace App\Exceptions;

use Exception;

class NotEnoughStock extends Exception
{
    public function __construct(public int $available)
    {
        parent::__construct("Only {$available} on the shelf.");
    }

    public function userMessage(): string
    {
        return "Only {$this->available} on the shelf.";
    }
}
