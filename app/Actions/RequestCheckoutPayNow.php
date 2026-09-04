<?php

namespace App\Actions;

use App\Enums\CheckoutStatus;
use App\Enums\PaymentRequestStatus;
use App\Exceptions\EmptyBasket;
use App\Exceptions\MomoRequestFailed;
use App\Models\Checkout;
use App\Models\PaymentRequest;
use App\Models\Tab;
use App\Services\Momo\MomoCollections;
use App\Services\Momo\RequestToPay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RequestCheckoutPayNow
{
    public function __construct(
        protected MomoCollections $momo,
        protected ReopenCheckout $reopenCheckout,
    ) {}

    /**
     * Collect MoMo for this checkout. Pay Now takes the whole basket.
     * Hybrid confirm collects only momo_amount and keeps the tab rail.
     *
     * 202 is not paid. GET SUCCESSFUL is. A second tap reuses the in-flight
     * UUID for this checkout so the customer is not charged twice.
     *
     * @throws MomoRequestFailed
     */
    public function handle(Checkout $checkout): PaymentRequest
    {
        $paymentRequest = DB::transaction(function () use ($checkout): PaymentRequest {
            $locked = Checkout::query()->whereKey($checkout->getKey())->lockForUpdate()->firstOrFail();
            $tab = Tab::query()->whereKey($locked->tab_id)->lockForUpdate()->firstOrFail();
            $tab->loadMissing(['customer', 'merchant.merchantProfile']);
            $locked->loadMissing('lines');

            $inFlight = $locked->paymentRequests()
                ->awaitingFinalStatus()
                ->latest('id')
                ->first();

            if ($inFlight !== null) {
                return $inFlight;
            }

            $collectingHybrid = $locked->isAwaitingConfirmation() && $locked->isHybrid();

            if (! $locked->isOpen() && ! $locked->isAwaitingMomo() && ! $collectingHybrid) {
                throw new \RuntimeException("Checkout {$locked->id} cannot collect MoMo.");
            }

            $total = $locked->total();

            if (bccomp($total, '0.00', 2) !== 1 || $locked->lines->isEmpty()) {
                throw new EmptyBasket;
            }

            $amount = $collectingHybrid ? ($locked->momo_amount ?? '0.00') : $total;

            if (bccomp($amount, '0.00', 2) !== 1) {
                throw new EmptyBasket;
            }

            $paymentRequest = $locked->paymentRequests()->make([
                'tab_id' => $tab->id,
                'reference_id' => Str::uuid()->toString(),
                'amount' => $amount,
                'currency' => (string) config('services.momo.currency'),
                'payer_msisdn' => $tab->customer->msisdn,
            ]);
            $paymentRequest->save();

            $paymentRequest->external_reference = "checkout-{$locked->id}-{$paymentRequest->id}";
            $paymentRequest->save();

            if ($locked->isOpen()) {
                $locked->momo_amount = $total;
                $locked->tab_amount = '0.00';
            }

            $locked->status = CheckoutStatus::AwaitingMomo;
            $locked->save();

            return $paymentRequest;
        });

        if ($paymentRequest->requested_at !== null) {
            return $paymentRequest;
        }

        return $this->send($paymentRequest, $checkout);
    }

    /**
     * @throws MomoRequestFailed
     */
    protected function send(PaymentRequest $paymentRequest, Checkout $checkout): PaymentRequest
    {
        $tab = $checkout->tab()->with(['merchant.merchantProfile'])->firstOrFail();

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

            $this->reopenCheckout->handle($checkout);

            throw $exception;
        }

        $paymentRequest->status = PaymentRequestStatus::Pending;
        $paymentRequest->requested_at = now();
        $paymentRequest->save();

        return $paymentRequest;
    }

    protected function message(Tab $tab): string
    {
        $business = $tab->merchant->merchantProfile?->business_name ?? $tab->merchant->name;

        return Str::limit(
            Str::of("Till for {$business}")->replace(["'", '’'], '')->trim()->value(),
            150,
            '',
        );
    }
}
