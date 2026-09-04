<?php

namespace App\Services\Momo;

/**
 * A single Request to Pay, already resolved to the values MoMo expects.
 *
 * The reference id is the caller's, not ours to invent: it is persisted before
 * the call so a retry reuses it and MoMo recognises the request rather than
 * debiting the customer twice.
 */
class RequestToPay
{
    public function __construct(
        public readonly string $referenceId,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $payerMsisdn,
        public readonly string $externalId,
        public readonly string $payerMessage,
        public readonly string $payeeNote,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toBody(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'externalId' => $this->externalId,
            'payer' => [
                'partyIdType' => 'MSISDN',
                'partyId' => $this->payerMsisdn,
            ],
            'payerMessage' => $this->payerMessage,
            'payeeNote' => $this->payeeNote,
        ];
    }
}
