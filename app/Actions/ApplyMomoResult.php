<?php

namespace App\Actions;

use App\Enums\MomoTransactionStatus;
use App\Models\PaymentRequest;
use App\Services\Momo\MomoCollections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ApplyMomoResult
{
    public function __construct(
        protected MomoCollections $momo,
        protected ApplyPaymentCredit $applyPaymentCredit,
        protected CompleteCheckout $completeCheckout,
        protected RecordCheckoutOnTab $recordCheckoutOnTab,
        protected ReopenCheckout $reopenCheckout,
    ) {}

    /**
     * Bring a payment request to its final state, settling the entries it
     * snapshotted if MoMo took the money.
     *
     * The status is always fetched from MoMo rather than read from a callback
     * body, because a callback is an unauthenticated request from the internet
     * and is only ever a hint that something changed.
     *
     * Safe to call repeatedly: a request that is already final is left alone,
     * which is what makes a replayed callback harmless.
     */
    public function handle(PaymentRequest $paymentRequest): PaymentRequest
    {
        if ($paymentRequest->isFinal()) {
            return $paymentRequest;
        }

        $transaction = $this->momo->status($paymentRequest->reference_id);

        if (! $transaction->status->isFinal()) {
            return $paymentRequest;
        }

        return DB::transaction(function () use ($paymentRequest, $transaction): PaymentRequest {
            $locked = PaymentRequest::whereKey($paymentRequest->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isFinal()) {
                return $locked;
            }

            $locked->status = $transaction->status->toPaymentRequestStatus();
            $locked->failure_reason = $transaction->reason;
            $locked->completed_at = now();
            $locked->raw_response = $transaction->raw;
            $locked->save();

            if ($transaction->status === MomoTransactionStatus::Failed) {
                Log::info('MoMo payment failed', [
                    'reference_id' => $locked->reference_id,
                    'reason' => $transaction->reason,
                    'payload_keys' => array_keys($transaction->raw),
                ]);
            }

            if ($locked->checkout_id !== null) {
                $checkout = $locked->checkout()->lockForUpdate()->first();

                if ($checkout !== null) {
                    if ($transaction->status->isSuccessful()) {
                        $this->recordCheckoutOnTab->handle($checkout);
                        $this->completeCheckout->handle($checkout);
                    } else {
                        $this->reopenCheckout->handle($checkout);
                    }
                }
            } elseif ($transaction->status->isSuccessful()) {
                $this->applyPaymentCredit->handle($locked);
            }

            return $locked->fresh();
        });
    }
}
