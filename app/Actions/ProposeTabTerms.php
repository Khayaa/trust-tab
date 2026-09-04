<?php

namespace App\Actions;

use App\Events\TabUpdated;
use App\Models\Tab;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProposeTabTerms
{
    /**
     * The merchant proposes a limit and payday. The customer must accept
     * before anything can go on the tab. Changing the terms clears a previous
     * acceptance so both sides look at the same agreement again.
     */
    public function handle(Tab $tab, User $merchant, string $limitAmount, int $settlementDay): Tab
    {
        $this->assertValidTerms($limitAmount, $settlementDay);

        return DB::transaction(function () use ($tab, $limitAmount, $settlementDay): Tab {
            $locked = Tab::query()->whereKey($tab->getKey())->lockForUpdate()->firstOrFail();

            if (bccomp($limitAmount, $locked->outstandingBalance(), 2) === -1) {
                throw ValidationException::withMessages([
                    'limitAmount' => 'The limit cannot be below what is already outstanding.',
                ]);
            }

            $termsChanged = $locked->limit_amount === null
                || $locked->settlement_day === null
                || bccomp($locked->limit_amount, $limitAmount, 2) !== 0
                || (int) $locked->settlement_day !== $settlementDay;

            $locked->limit_amount = $limitAmount;
            $locked->settlement_day = $settlementDay;

            if ($termsChanged) {
                $locked->agreement_accepted_at = null;
            }

            $locked->save();

            TabUpdated::dispatch($locked->id);

            return $locked;
        });
    }

    protected function assertValidTerms(string $limitAmount, int $settlementDay): void
    {
        if (! is_numeric($limitAmount) || bccomp($limitAmount, '0.01', 2) === -1) {
            throw ValidationException::withMessages([
                'limitAmount' => 'Enter a limit of at least 0.01.',
            ]);
        }

        if ($settlementDay < 1 || $settlementDay > 31) {
            throw ValidationException::withMessages([
                'settlementDay' => 'Payday must be a day of the month, from 1 to 31.',
            ]);
        }
    }
}
