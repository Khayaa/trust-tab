<?php

namespace App\Enums;

enum TabEntryStatus: string
{
    case PendingConfirmation = 'pending_confirmation';
    case Confirmed = 'confirmed';
    case Disputed = 'disputed';
    case Withdrawn = 'withdrawn';
    case Settled = 'settled';

    /**
     * Whether the entry counts toward the payable outstanding balance.
     */
    public function isOutstanding(): bool
    {
        return $this === self::Confirmed;
    }

    /**
     * Whether the entry can no longer change.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Withdrawn, self::Settled], strict: true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingConfirmation => 'Awaiting confirmation',
            self::Confirmed => 'Confirmed',
            self::Disputed => 'Disputed',
            self::Withdrawn => 'Withdrawn',
            self::Settled => 'Settled',
        };
    }
}
