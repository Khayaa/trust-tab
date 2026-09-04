<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * A mobile number as MoMo and this app both store it: digits only, in
 * international form, with no plus sign.
 *
 * South African numbers typed the way a shopkeeper writes them (082 123 4567)
 * are rewritten to 27821234567. Anything already international is left alone
 * after stripping spaces and punctuation.
 */
class Msisdn
{
    /**
     * @throws \InvalidArgumentException
     */
    public static function normalize(string $value): string
    {
        $digits = Str::of($value)
            ->replaceMatches('/\D+/', '')
            ->whenStartsWith('00', fn ($number) => $number->substr(2))
            ->value();

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = '27'.substr($digits, 1);
        }

        if (! preg_match('/^[1-9]\d{9,14}$/', $digits)) {
            throw new \InvalidArgumentException('That is not a mobile number.');
        }

        return $digits;
    }

    public static function tryNormalize(string $value): ?string
    {
        try {
            return self::normalize($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The national form a shopkeeper reads, e.g. 082 123 4567. Storage stays
     * international so MoMo still receives 27821234567.
     */
    public static function forDisplay(string $msisdn): string
    {
        if (strlen($msisdn) === 11 && str_starts_with($msisdn, '27')) {
            $national = '0'.substr($msisdn, 2);

            return substr($national, 0, 3).' '.substr($national, 3, 3).' '.substr($national, 6);
        }

        return $msisdn;
    }
}
