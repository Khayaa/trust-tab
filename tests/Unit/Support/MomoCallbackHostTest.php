<?php

use App\Support\MomoCallbackHost;

it('keeps a bare hostname', function () {
    expect(MomoCallbackHost::resolve('trusttab.laravel.cloud'))->toBe('trusttab.laravel.cloud');
});

it('strips a scheme and path so momo can match the registered host', function (string $value) {
    expect(MomoCallbackHost::resolve($value))->toBe('trusttab.laravel.cloud');
})->with([
    'https url' => ['https://trusttab.laravel.cloud/momo/callback'],
    'double scheme' => ['https://https://trusttab.laravel.cloud'],
    'truncated scheme' => ['ttps://trusttab.laravel.cloud'],
    'port' => ['trusttab.laravel.cloud:443'],
]);

it('treats a blank value as unset', function (?string $value) {
    expect(MomoCallbackHost::resolve($value))->toBeNull();
})->with([
    'empty' => [''],
    'null' => [null],
]);
