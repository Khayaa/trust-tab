<?php

namespace App\Enums;

enum PaymentRequestStatus: string
{
    case Created = 'created';
    case Pending = 'pending';
    case Successful = 'successful';
    case Failed = 'failed';

    /**
     * Whether MoMo has reached a terminal state for this request.
     *
     * Only a final state may settle or release entries, and a request that is
     * already final must never be processed a second time.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Successful, self::Failed], strict: true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Preparing',
            self::Pending => 'Awaiting approval',
            self::Successful => 'Paid',
            self::Failed => 'Not completed',
        };
    }
}
