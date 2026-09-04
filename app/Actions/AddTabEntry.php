<?php

namespace App\Actions;

use App\Exceptions\TabAgreementRequired;
use App\Exceptions\TabLimitExceeded;
use App\Models\Tab;
use App\Models\TabEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AddTabEntry
{
    /**
     * Record something the customer took on credit. The entry starts unconfirmed
     * and contributes nothing to the balance until the customer agrees to it.
     *
     * @param  array{description: string, amount: string, note?: string|null}  $attributes
     */
    public function handle(Tab $tab, User $merchant, array $attributes): TabEntry
    {
        return DB::transaction(function () use ($tab, $merchant, $attributes): TabEntry {
            $locked = Tab::query()->whereKey($tab->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->hasAcceptedAgreement()) {
                throw new TabAgreementRequired;
            }

            if ($locked->wouldExceedLimit($attributes['amount'])) {
                throw new TabLimitExceeded;
            }

            return $locked->entries()->create([
                'created_by' => $merchant->id,
                'description' => $attributes['description'],
                'amount' => $attributes['amount'],
                'note' => $attributes['note'] ?? null,
            ]);
        });
    }
}
