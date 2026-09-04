<?php

use App\Enums\MomoTransactionStatus;
use App\Services\Momo\MomoTransaction;

it('reads the failure code from the shapes momo actually sends', function (array $payload, ?string $reason) {
    $transaction = MomoTransaction::fromPayload($payload);

    expect($transaction->status)->toBe(MomoTransactionStatus::Failed)
        ->and($transaction->reason)->toBe($reason);
})->with([
    'reason string' => [['status' => 'FAILED', 'reason' => 'NOT_ALLOWED'], 'NOT_ALLOWED'],
    'lowercase status' => [['status' => 'failed', 'reason' => 'not_enough_funds'], 'NOT_ENOUGH_FUNDS'],
    'spaced reason' => [['status' => 'FAILED', 'reason' => 'INTERNAL PROCESSING ERROR'], 'INTERNAL_PROCESSING_ERROR'],
    'capitalised key' => [['status' => 'FAILED', 'Reason' => 'PAYEE_NOT_FOUND'], 'PAYEE_NOT_FOUND'],
    'nested reason' => [['status' => 'FAILED', 'reason' => ['code' => 'PAYER_LIMIT_REACHED']], 'PAYER_LIMIT_REACHED'],
    'nested message' => [['status' => 'FAILED', 'reason' => ['message' => 'APPROVAL REJECTED']], 'APPROVAL_REJECTED'],
    'top-level code' => [['status' => 'FAILED', 'code' => 'SENDER_ACCOUNT_NOT_ACTIVE'], 'SENDER_ACCOUNT_NOT_ACTIVE'],
    'no reason' => [['status' => 'FAILED'], null],
]);
