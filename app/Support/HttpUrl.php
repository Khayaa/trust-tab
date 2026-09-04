<?php

namespace App\Support;

/**
 * Outbound HTTP base URLs as Guzzle accepts them. Hosted URL fields sometimes
 * drop the leading "h" of https, which leaves ttps:// and every call then
 * fails with "The scheme 'ttps' is not supported."
 */
class HttpUrl
{
    public static function normalize(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return $url;
        }

        if (str_starts_with($url, 'ttps://')) {
            $url = 'h'.$url;
        }

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = 'https://'.ltrim($url, '/');
        }

        return rtrim($url, '/');
    }
}
