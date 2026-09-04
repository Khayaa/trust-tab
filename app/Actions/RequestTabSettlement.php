<?php

namespace App\Actions;

use App\Enums\PaymentRequestStatus;
use App\Exceptions\MomoRequestFailed;
use App\Models\PaymentRequest;
use App\Models\Tab;
use App\Services\Momo\MomoCollections;
use App\Services\Momo\RequestToPay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RequestTabSettlement
{
    public function __construct(protected MomoCollections $momo) {}

    /**
     * Ask the customer to pay some or all of what is currently confirmed.
     *
     * The set of outstanding entries is snapshotted before MoMo is called, so
     * an entry confirmed while the customer is entering their PIN is not
     * silently swept into a payment they never agreed to. A partial amount
     * settles the oldest snapshotted lines in full and leaves any remainder
     * as unallocated credit.
     *
     * @throws MomoRequestFailed
     */
    public function handle(Tab $tab, ?string $amount = null): PaymentRequest
    {
        $paymentRequest = DB::transaction(function () use ($tab, $amount): PaymentRequest {
            $locked = Tab::query()->whereKey($tab->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing('customer');

            $inFlight = $locked->paymentRequests()
                ->forTabSettlement()
                ->awaitingFinalStatus()
                ->latest('id')
                ->first();

            if ($inFlight !== null) {
                return $inFlight;
            }

            $entries = $locked->entries()
                ->outstanding()
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $payable = $locked->outstandingBalance();

            if ($entries->isEmpty() || bccomp($payable, '0.00', 2) !== 1) {
                throw new RuntimeException("Tab {$locked->id} has nothing outstanding to settle.");
            }

            $amount ??= $payable;

            if (! is_numeric($amount) || bccomp($amount, '0.01', 2) === -1) {
                throw ValidationException::withMessages([
                    'settlementAmount' => 'Enter an amount of at least 0.01.',
                ]);
            }

            if (bccomp($amount, $payable, 2) === 1) {
                throw ValidationException::withMessages([
                    'settlementAmount' => 'That is more than this tab currently owes.',
                ]);
            }

            $paymentRequest = $locked->paymentRequests()->create([
                /**
                 * MoMo requires UUID v4. Eloquent's HasUuids would give v7 here,
                 * which MoMo rejects, so the id is generated explicitly.
                 */
                'reference_id' => Str::uuid()->toString(),
                'amount' => $amount,
                'currency' => (string) config('services.momo.currency'),
                'payer_msisdn' => $locked->customer->msisdn,
            ]);

            /**
             * Derived from the primary key rather than a timestamp, because two
             * attempts on the same tab within one second would otherwise
             * collide on the unique index and fail the merchant's retry.
             */
            $paymentRequest->external_reference = "tab-{$locked->id}-{$paymentRequest->id}";
            $paymentRequest->save();

            $paymentRequest->snapshotEntries()->createMany(
                $entries->map(fn ($entry) => [
                    'tab_entry_id' => $entry->id,
                    'amount' => $entry->amount,
                ])->all(),
            );

            return $paymentRequest;
        });

        if ($paymentRequest->requested_at !== null) {
            return $paymentRequest;
        }

        return $this->send($paymentRequest, $tab);
    }

    /**
     * @throws MomoRequestFailed
     */
    protected function send(PaymentRequest $paymentRequest, Tab $tab): PaymentRequest
    {
        try {
            $this->momo->requestToPay(new RequestToPay(
                referenceId: $paymentRequest->reference_id,
                amount: $paymentRequest->amount,
                currency: $paymentRequest->currency,
                payerMsisdn: $paymentRequest->payer_msisdn,
                externalId: $paymentRequest->external_reference,
                payerMessage: $this->message($tab),
                payeeNote: $this->message($tab),
            ));
        } catch (MomoRequestFailed $exception) {
            $paymentRequest->status = PaymentRequestStatus::Failed;
            $paymentRequest->failure_reason = $exception->momoCode ?? 'REQUEST_REJECTED';
            $paymentRequest->completed_at = now();
            $paymentRequest->save();

            throw $exception;
        }

        $paymentRequest->status = PaymentRequestStatus::Pending;
        $paymentRequest->requested_at = now();
        $paymentRequest->save();

        return $paymentRequest;
    }

    /**
     * MoMo caps this at 160 characters and rejects apostrophes, so the business
     * name is stripped of both rather than trusted.
     */
    protected function message(Tab $tab): string
    {
        $business = $tab->merchant->merchantProfile?->business_name ?? $tab->merchant->name;

        return Str::limit(
            Str::of("Tab settlement for {$business}")->replace(["'", '’'], '')->trim()->value(),
            150,
            '',
        );
    }
}
