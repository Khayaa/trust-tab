<?php

namespace App\Enums;

enum CheckoutStatus: string
{
    case Open = 'open';
    case AwaitingMomo = 'awaiting_momo';
    case AwaitingConfirmation = 'awaiting_confirmation';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    public function isAwaitingMomo(): bool
    {
        return $this === self::AwaitingMomo;
    }

    public function isAwaitingConfirmation(): bool
    {
        return $this === self::AwaitingConfirmation;
    }

    public function isCompleted(): bool
    {
        return $this === self::Completed;
    }
}
