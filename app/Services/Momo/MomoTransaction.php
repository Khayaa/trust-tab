<?php

namespace App\Services\Momo;

use App\Enums\MomoTransactionStatus;

class MomoTransaction
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly MomoTransactionStatus $status,
        public readonly ?string $reason = null,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $status = MomoTransactionStatus::tryFrom(strtoupper((string) ($payload['status'] ?? '')))
            ?? MomoTransactionStatus::Pending;

        return new self(
            status: $status,
            reason: self::reasonFrom($payload),
            raw: $payload,
        );
    }

    /**
     * Production GET status often puts the machine code in reason; some
     * environments nest it, capitalise the key, or send "INTERNAL PROCESSING
     * ERROR" with spaces. A 202 Request to Pay is not success — this is where
     * MoMo says why it later failed.
     *
     * @param  array<string, mixed>  $payload
     */
    protected static function reasonFrom(array $payload): ?string
    {
        $folded = [];

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $folded[strtolower($key)] = $value;
            }
        }

        foreach (['reason', 'code', 'statusreason', 'errorcode', 'failurereason', 'error'] as $key) {
            $code = self::codeFrom($folded[$key] ?? null);

            if ($code !== null) {
                return $code;
            }
        }

        return null;
    }

    protected static function codeFrom(mixed $value): ?string
    {
        if (is_array($value)) {
            $picked = null;

            foreach ($value as $innerKey => $innerValue) {
                if (! is_string($innerKey) || ! is_string($innerValue) || $innerValue === '') {
                    continue;
                }

                $foldedKey = strtolower($innerKey);

                if ($foldedKey === 'code') {
                    $picked = $innerValue;
                    break;
                }

                if ($foldedKey === 'message' && $picked === null) {
                    $picked = $innerValue;
                }
            }

            $value = $picked;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return strtoupper((string) preg_replace('/[\s\-]+/', '_', trim($value)));
    }

    public static function pending(): self
    {
        return new self(MomoTransactionStatus::Pending);
    }
}
