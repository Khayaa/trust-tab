<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\Client\Response;
use Throwable;

class MomoRequestFailed extends Exception
{
    public function __construct(
        string $message,
        public readonly ?string $momoCode = null,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function fromResponse(Response $response): self
    {
        $code = $response->json('code');

        return new self(
            sprintf('MoMo rejected the request with %s: %s', $response->status(), $code ?? $response->body()),
            momoCode: is_string($code) ? $code : null,
            status: $response->status(),
        );
    }

    /**
     * Safe to show a merchant or customer: MoMo's own codes name internal
     * configuration problems that a shopkeeper can do nothing about.
     */
    public static function messageForCode(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        $code = strtoupper((string) preg_replace('/[\s\-]+/', '_', trim($code)));

        return match ($code) {
            'PAYER_NOT_FOUND' => 'That mobile number is not registered for MoMo.',
            'NOT_ENOUGH_FUNDS', 'PAYER_LIMIT_REACHED', 'LOW_BALANCE_OR_PAYEE_LIMIT_REACHED_OR_NOT_ALLOWED' => 'There is not enough money in the wallet to pay this.',
            'PAYER_REJECTED', 'APPROVAL_REJECTED', 'REJECTED' => 'The customer declined the MoMo prompt.',
            'EXPIRED', 'COULD_NOT_PERFORM_TRANSACTION' => 'The MoMo prompt expired before it was approved.',
            'SENDER_ACCOUNT_NOT_ACTIVE' => 'That MoMo wallet cannot pay right now.',
            'INVALID_CURRENCY' => 'MoMo rejected the settlement currency. Try again.',
            'INVALID_CALLBACK_URL_HOST' => 'MoMo could not send us the result. Try again.',
            'INTERNAL_PROCESSING_ERROR', 'SERVICE_UNAVAILABLE', 'NOT_ALLOWED', 'PAYEE_NOT_FOUND' => 'MoMo could not complete this payment. Try again in a moment.',
            default => null,
        };
    }

    public function userMessage(): string
    {
        return self::messageForCode($this->momoCode)
            ?? 'We could not reach MoMo just now. Please try again.';
    }
}
