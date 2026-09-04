<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Ramsey\Uuid\Uuid;

function fakeProvisioning(array $overrides = []): void
{
    Http::fake(array_merge([
        '*/v1_0/apiuser' => Http::response(status: 201),
        '*/v1_0/apiuser/*/apikey' => Http::response(['apiKey' => 'the-api-key'], 201),
        '*/collection/token/' => Http::response(['access_token' => 'a-token', 'expires_in' => 3600]),
    ], $overrides));
}

it('provisions a user and key, then proves they mint a token', function () {
    fakeProvisioning();

    $this->artisan('momo:provision --subscription-key=sub-key --host=trusttab.test')
        ->expectsOutputToContain('API user created.')
        ->expectsOutputToContain('API key created.')
        ->expectsOutputToContain('Access token granted.')
        ->expectsOutputToContain('MOMO_API_KEY=the-api-key')
        ->assertSuccessful();
});

it('identifies the api user with a version 4 uuid', function () {
    fakeProvisioning();

    $this->artisan('momo:provision --subscription-key=sub-key')->assertSuccessful();

    Http::assertSent(function (Request $request) {
        if (! str_ends_with($request->url(), '/v1_0/apiuser')) {
            return false;
        }

        $apiUser = $request->header('X-Reference-Id')[0];

        return Uuid::fromString($apiUser)->getFields()->getVersion() === 4;
    });
});

it('registers a bare hostname, because momo compares callbacks against it', function (string $host) {
    fakeProvisioning();

    $this->artisan('momo:provision --subscription-key=sub-key --host='.$host)
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1_0/apiuser')
        && $request['providerCallbackHost'] === 'trusttab.test');
})->with([
    'https url' => ['https://trusttab.test/momo/callback'],
    'truncated scheme' => ['ttps://trusttab.test'],
]);

it('sends the subscription key on every provisioning call', function () {
    fakeProvisioning();

    $this->artisan('momo:provision --subscription-key=sub-key')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->header('Ocp-Apim-Subscription-Key')[0] === 'sub-key');
    Http::assertSentCount(3);
});

it('refuses to start without a subscription key', function () {
    Http::fake();
    config()->set('services.momo.subscription_key', null);

    $this->artisan('momo:provision')
        ->expectsOutputToContain('No subscription key.')
        ->assertFailed();

    Http::assertNothingSent();
});

it('refuses to post at this app instead of the momo sandbox', function () {
    Http::fake();
    config()->set('services.momo.base_url', 'https://trusttab.laravel.cloud');

    $this->artisan('momo:provision --subscription-key=sub-key')
        ->expectsOutputToContain('MOMO_BASE_URL must be the MoMo sandbox')
        ->expectsOutputToContain('https://trusttab.laravel.cloud')
        ->assertFailed();

    Http::assertNothingSent();
});

it('stops at the first step that momo rejects', function () {
    fakeProvisioning(['*/v1_0/apiuser' => Http::response(status: 401)]);

    $this->artisan('momo:provision --subscription-key=wrong')
        ->expectsOutputToContain('Creating the API user failed with 401.')
        ->assertFailed();

    Http::assertSentCount(1);
});

it('reports credentials that exist but cannot authenticate', function () {
    fakeProvisioning(['*/collection/token/' => Http::response(status: 401)]);

    $this->artisan('momo:provision --subscription-key=sub-key')
        ->expectsOutputToContain('Requesting an access token failed with 401.')
        ->expectsOutputToContain('Collection product')
        ->assertFailed();
});
