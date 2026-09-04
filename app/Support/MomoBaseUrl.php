<?php

namespace App\Support;

/**
 * Laravel Cloud URL fields often store APP_URL. Using that as the MoMo base
 * posts /v1_0/apiuser at TrustTab, which 404s with Laravel's "route could not
 * be found" — not a MoMo error.
 */
class MomoBaseUrl
{
    public const SANDBOX = 'https://sandbox.momodeveloper.mtn.com';

    public const PRODUCTION = 'https://proxy.momoapi.mtn.com';

    public static function resolve(string $configured, string $appUrl): string
    {
        $url = HttpUrl::normalize($configured !== '' ? $configured : self::SANDBOX);
        $urlHost = parse_url($url, PHP_URL_HOST);
        $appHost = parse_url($appUrl, PHP_URL_HOST);

        if (! is_string($urlHost) || $urlHost === '') {
            return self::SANDBOX;
        }

        if (is_string($appHost) && strcasecmp($urlHost, $appHost) === 0) {
            return self::SANDBOX;
        }

        return $url;
    }
}
