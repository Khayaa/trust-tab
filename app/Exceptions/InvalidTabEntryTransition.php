<?php

namespace App\Exceptions;

use App\Enums\TabEntryStatus;
use App\Models\TabEntry;
use Exception;

class InvalidTabEntryTransition extends Exception
{
    public static function from(TabEntry $entry, TabEntryStatus $target): self
    {
        return new self(sprintf(
            'Tab entry %s cannot move from %s to %s.',
            $entry->id,
            $entry->status->value,
            $target->value,
        ));
    }

    /**
     * Shown to the customer or merchant, so it must stay free of internal detail.
     */
    public function userMessage(): string
    {
        return 'That item has already been updated. Refresh to see the latest.';
    }
}
