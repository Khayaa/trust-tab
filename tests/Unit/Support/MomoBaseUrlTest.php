<?php

use App\Support\MomoBaseUrl;

it('falls back to the sandbox when the value is this application', function (string $configured, string $appUrl) {
    expect(MomoBaseUrl::resolve($configured, $appUrl))->toBe(MomoBaseUrl::SANDBOX);
})->with([
    'app url' => ['https://trusttab.laravel.cloud', 'https://trusttab.laravel.cloud'],
    'empty' => ['', 'https://trusttab.laravel.cloud'],
    'truncated scheme on the app' => ['ttps://trusttab.laravel.cloud', 'https://trusttab.laravel.cloud'],
]);

it('keeps a real momo sandbox url', function () {
    expect(MomoBaseUrl::resolve(
        'ttps://sandbox.momodeveloper.mtn.com/',
        'https://trusttab.laravel.cloud',
    ))->toBe(MomoBaseUrl::SANDBOX);
});

it('keeps the south africa collection proxy', function () {
    expect(MomoBaseUrl::resolve(
        'https://proxy.momoapi.mtn.com/',
        'https://trusttab.laravel.cloud',
    ))->toBe(MomoBaseUrl::PRODUCTION);
});
