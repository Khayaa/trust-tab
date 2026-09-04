<?php

use App\Support\HttpUrl;

it('repairs a truncated https scheme so guzzle can send the request', function (string $input, string $expected) {
    expect(HttpUrl::normalize($input))->toBe($expected);
})->with([
    'ttps' => ['ttps://sandbox.momodeveloper.mtn.com', 'https://sandbox.momodeveloper.mtn.com'],
    'https' => ['https://sandbox.momodeveloper.mtn.com', 'https://sandbox.momodeveloper.mtn.com'],
    'trailing slash' => ['https://sandbox.momodeveloper.mtn.com/', 'https://sandbox.momodeveloper.mtn.com'],
    'host only' => ['sandbox.momodeveloper.mtn.com', 'https://sandbox.momodeveloper.mtn.com'],
    'http left alone' => ['http://localhost:8080', 'http://localhost:8080'],
    'blank' => ['', ''],
]);
