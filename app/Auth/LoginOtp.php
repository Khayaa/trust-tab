<?php

namespace App\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * A one-time sign-in code, hashed in cache and thrown away after use.
 *
 * The plaintext never goes in the database. A production SMS sender would
 * receive it once from SendLoginOtp and then forget it. The reveal cache is
 * only written when trusttab.otp.reveal is on, so a deployed app cannot leak
 * the code onto the login page by accident.
 */
class LoginOtp
{
    public const TTL_MINUTES = 5;

    public function issue(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function store(string $msisdn, string $code): void
    {
        Cache::put($this->hashKey($msisdn), Hash::make($code), now()->addMinutes(self::TTL_MINUTES));
    }

    public function check(string $msisdn, string $code): bool
    {
        $hash = Cache::get($this->hashKey($msisdn));

        return is_string($hash) && Hash::check($code, $hash);
    }

    public function forget(string $msisdn): void
    {
        Cache::forget($this->hashKey($msisdn));
        Cache::forget($this->revealKey($msisdn));
    }

    public function reveal(string $msisdn, string $code): void
    {
        if (! config('trusttab.otp.reveal')) {
            return;
        }

        Cache::put($this->revealKey($msisdn), $code, now()->addMinutes(self::TTL_MINUTES));
    }

    public function revealed(string $msisdn): ?string
    {
        if (! config('trusttab.otp.reveal')) {
            return null;
        }

        $code = Cache::get($this->revealKey($msisdn));

        return is_string($code) ? $code : null;
    }

    public function hashKey(string $msisdn): string
    {
        return "login.otp.hash.{$msisdn}";
    }

    public function revealKey(string $msisdn): string
    {
        return "login.otp.reveal.{$msisdn}";
    }
}
