<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo Authentication
    |--------------------------------------------------------------------------
    |
    | Lets anyone sign in as a seeded user by tapping their name, so the demo
    | can switch between the merchant and the customer on two devices without
    | passwords. This must never be enabled anywhere real, because it is an
    | unauthenticated route that hands out sessions.
    |
    */

    'demo_auth' => env('TRUSTTAB_DEMO_AUTH', false),

    /*
    |--------------------------------------------------------------------------
    | One-time Sign-in Codes
    |--------------------------------------------------------------------------
    |
    | Production sends the code as an SMS and never writes it back to the
    | browser. Reveal is for the laptop and the stage: there is no SMS
    | provider in this app yet, and a judge cannot wait for one.
    |
    */

    'otp' => [
        'reveal' => env('TRUSTTAB_OTP_REVEAL', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Money Display
    |--------------------------------------------------------------------------
    |
    | What the customer and merchant see. This is deliberately not the currency
    | we settle in: the MoMo sandbox only accepts EUR, which lives separately
    | under services.momo.currency. Never send this one to MoMo.
    |
    */

    'money' => [
        'currency' => env('DISPLAY_CURRENCY', 'ZAR'),
        'locale' => env('DISPLAY_LOCALE', 'en_ZA'),
    ],

];
