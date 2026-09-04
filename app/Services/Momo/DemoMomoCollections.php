<?php

namespace App\Services\Momo;

use App\Enums\MomoTransactionStatus;
use Illuminate\Support\Facades\Cache;

/**
 * Stands in for MoMo when the sandbox is unreachable, so a demo can still be
 * given on a bad conference network. It mirrors the sandbox's own behaviour:
 * the reserved 4673312345x numbers force an outcome, anything else succeeds.
 *
 * Enabled with services.momo.demo_mode. Never enable it where real money moves.
 */
class DemoMomoCollections implements MomoCollections
{
    /**
     * Taken from the sandbox use case table. Anything outside this block
     * succeeds, which is the documented sandbox behaviour and the reason demo
     * users are given numbers above 46733124000.
     */
    protected const FORCED_OUTCOMES = [
        '46733123450' => [MomoTransactionStatus::Failed, null],
        '46733123451' => [MomoTransactionStatus::Failed, 'PAYER_REJECTED'],
        '46733123452' => [MomoTransactionStatus::Failed, 'EXPIRED'],
        '46733123453' => [MomoTransactionStatus::Pending, null],
        '46733123454' => [MomoTransactionStatus::Pending, null],
        '46733123455' => [MomoTransactionStatus::Failed, 'PAYER_NOT_FOUND'],
        '46733123456' => [MomoTransactionStatus::Failed, 'PAYEE_NOT_ALLOWED_TO_RECEIVE'],
        '46733123457' => [MomoTransactionStatus::Failed, 'NOT_ALLOWED'],
        '46733123461' => [MomoTransactionStatus::Failed, 'INTERNAL_PROCESSING_ERROR'],
        '46733123462' => [MomoTransactionStatus::Failed, 'SERVICE_UNAVAILABLE'],
        '46733123463' => [MomoTransactionStatus::Failed, 'COULD_NOT_PERFORM_TRANSACTION'],
    ];

    public function requestToPay(RequestToPay $command): MomoTransaction
    {
        Cache::put(
            $this->key($command->referenceId),
            ['msisdn' => $command->payerMsisdn, 'requested_at' => now()->toIso8601String()],
            now()->addHour(),
        );

        return MomoTransaction::pending();
    }

    public function status(string $referenceId): MomoTransaction
    {
        $record = Cache::get($this->key($referenceId));

        if ($record === null) {
            return MomoTransaction::pending();
        }

        [$status, $reason] = self::FORCED_OUTCOMES[$record['msisdn']]
            ?? [MomoTransactionStatus::Successful, null];

        return new MomoTransaction(
            status: $status,
            reason: $reason,
            raw: array_filter(['status' => $status->value, 'reason' => $reason, 'demo' => true]),
        );
    }

    protected function key(string $referenceId): string
    {
        return "momo.demo.{$referenceId}";
    }
}
