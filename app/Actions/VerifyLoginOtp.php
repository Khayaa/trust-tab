<?php

namespace App\Actions;

use App\Auth\LoginOtp;
use App\Models\User;
use App\Support\Msisdn;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class VerifyLoginOtp
{
    public function __construct(protected LoginOtp $otp) {}

    /**
     * Exchange a code for the person behind the number.
     *
     * A wrong code, an expired code, and a number we have never seen all read
     * the same, so the form cannot confirm that a tab exists.
     */
    public function handle(string $msisdn, string $code): User
    {
        $msisdn = $this->normalized($msisdn);
        $code = preg_replace('/\D+/', '', $code) ?? '';

        $this->ensureIsNotRateLimited($msisdn);

        $user = User::query()->where('msisdn', $msisdn)->first();

        if ($user === null || strlen($code) !== 6 || ! $this->otp->check($msisdn, $code)) {
            RateLimiter::hit($this->throttleKey($msisdn), decaySeconds: 60);

            throw ValidationException::withMessages([
                'code' => 'That code is not right. Ask for a new one if it has expired.',
            ]);
        }

        RateLimiter::clear($this->throttleKey($msisdn));
        RateLimiter::clear('login-otp-send:'.$msisdn.'|'.request()->ip());

        $this->otp->forget($msisdn);

        return $user;
    }

    protected function normalized(string $value): string
    {
        $msisdn = Msisdn::tryNormalize($value);

        if ($msisdn === null) {
            throw ValidationException::withMessages([
                'msisdn' => 'Enter a mobile number, including the country code if it is not South African.',
            ]);
        }

        return $msisdn;
    }

    protected function ensureIsNotRateLimited(string $msisdn): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($msisdn), maxAttempts: 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey($msisdn));

        throw ValidationException::withMessages([
            'code' => "Too many attempts. Try again in {$seconds} seconds.",
        ]);
    }

    protected function throttleKey(string $msisdn): string
    {
        return 'login-otp-verify:'.$msisdn.'|'.request()->ip();
    }
}
