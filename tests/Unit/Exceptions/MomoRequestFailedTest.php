<?php

use App\Exceptions\MomoRequestFailed;

it('turns a momo failure code into a till sentence', function (string $code, string $message) {
    expect(MomoRequestFailed::messageForCode($code))->toBe($message);
})->with([
    'payer not found' => ['PAYER_NOT_FOUND', 'That mobile number is not registered for MoMo.'],
    'not enough funds' => ['NOT_ENOUGH_FUNDS', 'There is not enough money in the wallet to pay this.'],
    'payer limit' => ['PAYER_LIMIT_REACHED', 'There is not enough money in the wallet to pay this.'],
    'declined' => ['APPROVAL_REJECTED', 'The customer declined the MoMo prompt.'],
    'pin timeout' => ['COULD_NOT_PERFORM_TRANSACTION', 'The MoMo prompt expired before it was approved.'],
    'inactive wallet' => ['SENDER_ACCOUNT_NOT_ACTIVE', 'That MoMo wallet cannot pay right now.'],
    'partner blocked' => ['NOT_ALLOWED', 'MoMo could not complete this payment. Try again in a moment.'],
    'spaced internal error' => ['INTERNAL PROCESSING ERROR', 'MoMo could not complete this payment. Try again in a moment.'],
]);

it('hides codes a shopkeeper cannot act on', function () {
    expect(MomoRequestFailed::messageForCode('SOME_FUTURE_CODE'))->toBeNull();
});
