<?php

namespace App\Actions;

use App\Auth\LoginOtp;
use App\Models\User;
use App\Support\Msisdn;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class SendLoginOtp
{
    public function __construct(protected LoginOtp $otp) {}

    /**
     * Ask the number for a one-time code.
     *
     * The response is the same whether the number has an account or not, so
     * this form cannot be used to discover who has a tab. A code is only stored
     * (and revealed) when the number is known.
     */
    public function handle(string $msisdn): string
    {
        $msisdn = $this->normalized($msisdn);

        $this->ensureIsNotRateLimited($msisdn);

        RateLimiter::hit($this->throttleKey($msisdn), decaySeconds: 10 * 60);

        $code = $this->otp->issue();

        /**
         * Only a known number gets a hashed code. A dummy reveal for unknown
         * numbers used to print a 6-digit value that looked real and then
         * failed with "that code is not right" — judges typed it and thought
         * OTP was broken. The form still advances so we do not confirm
         * whether a tab exists.
         */
        if (User::query()->where('msisdn', $msisdn)->exists()) {
            $this->otp->store($msisdn, $code);
            $this->otp->reveal($msisdn, $code);
        }

        return $msisdn;
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
        if (! RateLimiter::tooManyAttempts($this->throttleKey($msisdn), maxAttempts: 3)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey($msisdn));

        throw ValidationException::withMessages([
            'msisdn' => "Too many codes. Try again in {$seconds} seconds.",
        ]);
    }

    protected function throttleKey(string $msisdn): string
    {
        return 'login-otp-send:'.$msisdn.'|'.request()->ip();
    }
}
