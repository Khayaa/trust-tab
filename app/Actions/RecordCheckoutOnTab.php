<?php

namespace App\Actions;

use App\Enums\TabEntryStatus;
use App\Models\Checkout;
use App\Models\Tab;

class RecordCheckoutOnTab
{
    /**
     * Write the agreed tab rail. A full-tab basket becomes one confirmed
     * entry per line. A hybrid remainder is one confirmed entry so the
     * ledger matches tab_amount, not the basket total.
     */
    public function handle(Checkout $checkout): Checkout
    {
        $checkout->loadMissing('lines');
        $tab = Tab::query()->whereKey($checkout->tab_id)->lockForUpdate()->firstOrFail();
        $tabAmount = $checkout->tab_amount ?? '0.00';

        if ($checkout->entries()->exists() || bccomp($tabAmount, '0.00', 2) !== 1) {
            return $checkout;
        }

        if (bccomp($tabAmount, $checkout->total(), 2) === 0) {
            foreach ($checkout->lines as $line) {
                $entry = $tab->entries()->make([
                    'checkout_id' => $checkout->id,
                    'created_by' => $tab->merchant_id,
                    'description' => $line->ledgerDescription(),
                    'amount' => $line->lineTotal(),
                ]);
                $entry->status = TabEntryStatus::Confirmed;
                $entry->confirmed_at = now();
                $entry->save();
            }

            return $checkout->fresh(['lines']);
        }

        $entry = $tab->entries()->make([
            'checkout_id' => $checkout->id,
            'created_by' => $tab->merchant_id,
            'description' => $checkout->remainderDescription(),
            'amount' => $tabAmount,
        ]);
        $entry->status = TabEntryStatus::Confirmed;
        $entry->confirmed_at = now();
        $entry->save();

        return $checkout->fresh(['lines']);
    }
}
