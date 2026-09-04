<?php

namespace App\Enums;

/**
 * The states MoMo itself reports for a Request to Pay, kept separate from our
 * own PaymentRequestStatus so a change in their vocabulary cannot silently
 * change the meaning of our records.
 */
enum MomoTransactionStatus: string
{
    case Pending = 'PENDING';
    case Successful = 'SUCCESSFUL';
    case Failed = 'FAILED';

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }

    public function isSuccessful(): bool
    {
        return $this === self::Successful;
    }

    public function toPaymentRequestStatus(): PaymentRequestStatus
    {
        return match ($this) {
            self::Pending => PaymentRequestStatus::Pending,
            self::Successful => PaymentRequestStatus::Successful,
            self::Failed => PaymentRequestStatus::Failed,
        };
    }
}
