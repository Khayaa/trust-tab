<?php

namespace App\Actions;

use App\Enums\PaymentRequestStatus;
use App\Enums\TabEntryStatus;
use App\Models\PaymentRequest;
use App\Models\Tab;
use Illuminate\Support\Facades\DB;

class ApplyPaymentCredit
{
    /**
     * Allocate a successful payment oldest-first against the entries it
     * snapshotted. A line is settled only when the pool covers it in full.
     * Leftover stays as unallocated credit on the payment so the next
     * successful debit can finish that line. Entry amounts are never rewritten.
     */
    public function handle(PaymentRequest $paymentRequest): PaymentRequest
    {
        return DB::transaction(function () use ($paymentRequest): PaymentRequest {
            $locked = PaymentRequest::query()->whereKey($paymentRequest->getKey())->lockForUpdate()->firstOrFail();

            $tab = Tab::query()->whereKey($locked->tab_id)->lockForUpdate()->firstOrFail();

            $snapshotIds = $locked->snapshotEntries()->pluck('tab_entry_id');

            $entries = $tab->entries()
                ->whereIn('id', $snapshotIds)
                ->outstanding()
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $prior = $tab->paymentRequests()
                ->forTabSettlement()
                ->whereKeyNot($locked->getKey())
                ->where('status', PaymentRequestStatus::Successful)
                ->lockForUpdate()
                ->get();

            $pool = $locked->amount;

            foreach ($prior as $credit) {
                if (bccomp($credit->unallocated_amount, '0.00', 2) !== 1) {
                    continue;
                }

                $pool = bcadd($pool, $credit->unallocated_amount, 2);
                $credit->unallocated_amount = '0.00';
                $credit->save();
            }

            foreach ($entries as $entry) {
                if (bccomp($pool, $entry->amount, 2) === -1) {
                    break;
                }

                $entry->status = TabEntryStatus::Settled;
                $entry->settled_at = now();
                $entry->save();

                $pool = bcsub($pool, $entry->amount, 2);
            }

            $locked->unallocated_amount = $pool;
            $locked->save();

            return $locked;
        });
    }
}
