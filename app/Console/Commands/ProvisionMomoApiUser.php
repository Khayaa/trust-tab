<?php

namespace App\Console\Commands;

use App\Support\MomoCallbackHost;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ProvisionMomoApiUser extends Command
{
    protected $signature = 'momo:provision
        {--subscription-key= : Defaults to services.momo.subscription_key}
        {--host= : Callback hostname to register, defaults to services.momo.callback_host}';

    protected $description = 'Create a sandbox MoMo API user and key, then prove they mint a token';

    /**
     * Only the sandbox exposes this. In production the API user and key come
     * from the partner portal, so running this against a live environment will
     * simply 404.
     */
    public function handle(): int
    {
        $subscriptionKey = $this->option('subscription-key')
            ?: config('services.momo.subscription_key');

        if (! is_string($subscriptionKey) || $subscriptionKey === '') {
            $this->components->error('No subscription key. Set MOMO_SUBSCRIPTION_KEY or pass --subscription-key.');
            $this->line('  Get one by subscribing to the Collection product at https://momodeveloper.mtn.com/products');

            return self::FAILURE;
        }

        $baseUrl = $this->sandboxBaseUrl();

        if ($baseUrl === null) {
            return self::FAILURE;
        }

        /**
         * This UUID becomes the API user's identity, so it is needed for every
         * later call. Losing it means starting again, because the key cannot be
         * read back afterwards.
         */
        $apiUser = Str::uuid()->toString();
        $callbackHost = $this->callbackHost();

        $this->components->twoColumnDetail('Sandbox', $baseUrl);
        $this->components->twoColumnDetail('API user', $apiUser);
        $this->components->twoColumnDetail('Callback host', $callbackHost);
        $this->newLine();

        $created = $this->request($subscriptionKey)
            ->withHeaders(['X-Reference-Id' => $apiUser])
            ->post('/v1_0/apiuser', ['providerCallbackHost' => $callbackHost]);

        if (! $created->created()) {
            return $this->reportFailure('Creating the API user', $created->status(), $created->body());
        }

        $this->components->info('API user created.');

        $keyResponse = $this->request($subscriptionKey)
            ->post("/v1_0/apiuser/{$apiUser}/apikey");

        if (! $keyResponse->created()) {
            return $this->reportFailure('Creating the API key', $keyResponse->status(), $keyResponse->body());
        }

        $apiKey = $keyResponse->json('apiKey');

        if (! is_string($apiKey) || $apiKey === '') {
            return $this->reportFailure('Creating the API key', $keyResponse->status(), 'no apiKey in the response');
        }

        $this->components->info('API key created.');

        if (! $this->confirmTokenWorks($subscriptionKey, $apiUser, $apiKey)) {
            return self::FAILURE;
        }

        $this->printEnv($apiUser, $apiKey);

        return self::SUCCESS;
    }

    /**
     * Provisioning proves nothing on its own. Minting a token here means the
     * credentials are known to work before anyone stands up to demo.
     */
    protected function confirmTokenWorks(string $subscriptionKey, string $apiUser, string $apiKey): bool
    {
        $token = $this->request($subscriptionKey)
            ->withBasicAuth($apiUser, $apiKey)
            ->post('/collection/token/');

        if ($token->failed() || ! is_string($token->json('access_token'))) {
            $this->reportFailure('Requesting an access token', $token->status(), $token->body());
            $this->line('  The user and key exist but cannot authenticate. Check that this');
            $this->line('  subscription key belongs to the Collection product.');

            return false;
        }

        $this->components->info('Access token granted. The credentials work.');

        return true;
    }

    protected function printEnv(string $apiUser, string $apiKey): void
    {
        $this->newLine();
        $this->components->warn('Copy these into .env now. MoMo will never show the key again.');
        $this->newLine();
        $this->line("MOMO_API_USER={$apiUser}");
        $this->line("MOMO_API_KEY={$apiKey}");
        $this->line('MOMO_DEMO_MODE=false');
        $this->newLine();
        $this->line('  Then run: php artisan config:clear');
    }

    /**
     * This command only exists on the sandbox. Pointing it at APP_URL posts
     * /v1_0/apiuser at TrustTab, which then 404s with "The route could not be
     * found" — that is Laravel, not MoMo.
     */
    protected function sandboxBaseUrl(): ?string
    {
        $baseUrl = rtrim((string) config('services.momo.base_url'), '/');
        $host = parse_url($baseUrl, PHP_URL_HOST);

        if ($host === 'sandbox.momodeveloper.mtn.com') {
            return $baseUrl;
        }

        $this->components->error('MOMO_BASE_URL must be the MoMo sandbox, not this app.');
        $this->line('  Set MOMO_BASE_URL=https://sandbox.momodeveloper.mtn.com as a plain string.');
        $this->line("  Current value: {$baseUrl}");
        $this->line('  trusttab.laravel.cloud belongs in MOMO_CALLBACK_HOST, not MOMO_BASE_URL.');
        $this->line('  Laravel Cloud applies env changes only after a redeploy, not after saving.');

        return null;
    }

    protected function request(string $subscriptionKey): PendingRequest
    {
        return Http::baseUrl((string) config('services.momo.base_url'))
            ->withHeaders(['Ocp-Apim-Subscription-Key' => $subscriptionKey])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15);
    }

    /**
     * A hostname, never a URL. MoMo compares the host of every callback against
     * this value and rejects a mismatch with INVALID_CALLBACK_URL_HOST.
     */
    protected function callbackHost(): string
    {
        $configured = $this->option('host') ?: config('services.momo.callback_host');

        return MomoCallbackHost::resolve(is_string($configured) ? $configured : null)
            ?? 'example.com';
    }

    protected function reportFailure(string $step, int $status, string $body): int
    {
        $this->components->error("{$step} failed with {$status}.");

        if ($body !== '') {
            $this->line('  '.Str::limit($body, 300));
        }

        return self::FAILURE;
    }
}
