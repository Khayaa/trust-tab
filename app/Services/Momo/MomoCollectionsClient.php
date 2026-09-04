<?php

namespace App\Services\Momo;

use App\Exceptions\MomoRequestFailed;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MomoCollectionsClient implements MomoCollections
{
    /**
     * MoMo answers this when a reference id has already been accepted. Because
     * we reuse one persisted UUID per payment request, a retry after a timeout
     * lands here, and it means the debit is already in flight, not that it
     * failed. Minting a fresh id to "fix" a 409 would charge the customer twice.
     */
    protected const ALREADY_ACCEPTED = 409;

    public function requestToPay(RequestToPay $command): MomoTransaction
    {
        $response = $this->postRequestToPay($command, $this->callbackUrl());

        /**
         * A callback host that does not match the API user fails the whole
         * debit. The same UUID is still unused after a 400, so a second POST
         * without the header can proceed; polling covers the missing callback.
         */
        if ($this->rejectedCallbackHost($response) && $this->callbackUrl() !== null) {
            Log::info('MoMo rejected the callback host; retrying without a callback', [
                'reference_id' => $command->referenceId,
            ]);

            $response = $this->postRequestToPay($command, null);
        }

        if ($response->accepted() || $response->status() === self::ALREADY_ACCEPTED) {
            return MomoTransaction::pending();
        }

        throw MomoRequestFailed::fromResponse($response);
    }

    public function status(string $referenceId): MomoTransaction
    {
        $response = $this->authenticated()
            ->get("/collection/v1_0/requesttopay/{$referenceId}");

        if ($response->failed()) {
            throw MomoRequestFailed::fromResponse($response);
        }

        return MomoTransaction::fromPayload($response->json() ?? []);
    }

    protected function authenticated(): PendingRequest
    {
        return Http::momo()->withToken($this->accessToken());
    }

    /**
     * Tokens are reused until they expire, as MoMo asks. The cache TTL is cut
     * short of the real expiry so a token cannot lapse mid-request.
     */
    protected function accessToken(): string
    {
        return Cache::remember($this->tokenCacheKey(), now()->addMinutes(50), function (): string {
            $response = Http::momo()
                ->withBasicAuth(
                    (string) config('services.momo.api_user'),
                    (string) config('services.momo.api_key'),
                )
                ->post('/collection/token/');

            if ($response->failed()) {
                throw MomoRequestFailed::fromResponse($response);
            }

            $token = $response->json('access_token');

            if (! is_string($token) || $token === '') {
                throw new MomoRequestFailed('MoMo returned no access token.');
            }

            return $token;
        });
    }

    protected function tokenCacheKey(): string
    {
        return 'momo.collection.token.'.config('services.momo.target_environment').'.'.config('services.momo.api_user');
    }

    protected function postRequestToPay(RequestToPay $command, ?string $callbackUrl): Response
    {
        return $this->authenticated()
            ->withHeaders(array_filter([
                'X-Reference-Id' => $command->referenceId,
                'X-Callback-Url' => $callbackUrl,
            ]))
            ->post('/collection/v1_0/requesttopay', $command->toBody());
    }

    protected function rejectedCallbackHost(Response $response): bool
    {
        return $response->json('code') === 'INVALID_CALLBACK_URL_HOST';
    }

    /**
     * MoMo validates this against the host registered on the API user and
     * rejects a mismatch with INVALID_CALLBACK_URL_HOST, so an unset host sends
     * no callback at all rather than a broken one. Reconciliation covers it.
     */
    protected function callbackUrl(): ?string
    {
        $host = config('services.momo.callback_host');

        if (! is_string($host) || $host === '') {
            return null;
        }

        return "https://{$host}/momo/callback";
    }
}
