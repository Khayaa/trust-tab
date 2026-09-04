<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * MoMo compares X-Callback-Url against the hostname registered on the API
 * user. Cloud URL fields often store a full URL, which would be sent as
 * https://https://… and rejected with INVALID_CALLBACK_URL_HOST.
 */
class MomoCallbackHost
{
    public static function resolve(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        while (preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) === 1) {
            $value = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $value, 1);
        }

        $host = Str::of($value)
            ->before('/')
            ->before('?')
            ->trim()
            ->value();

        if (preg_match('/^(.+):(\d+)$/', $host, $matches) === 1) {
            $host = $matches[1];
        }

        return $host === '' ? null : $host;
    }
}
