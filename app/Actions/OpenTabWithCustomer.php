<?php

namespace App\Actions;

use App\Enums\TabStatus;
use App\Models\Tab;
use App\Models\User;
use App\Support\Msisdn;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpenTabWithCustomer
{
    public function __construct(private ProposeTabTerms $proposeTabTerms) {}

    /**
     * Open a tab between this merchant and the person behind the MoMo number.
     *
     * The number is the identity. If they have never used TrustTab, a user is
     * created from it. If they already have an account — even at another shop —
     * that account is reused so one phone is not two people. An existing tab
     * with this merchant is returned rather than duplicated.
     *
     * The name is only used when creating someone new. An existing customer's
     * name is theirs, not the merchant's to overwrite.
     *
     * Limit and payday are proposed in the same transaction when provided. The
     * customer still has to accept before anything can go on the tab.
     */
    public function handle(User $merchant, string $name, string $msisdn, ?string $limitAmount = null, ?int $settlementDay = null): Tab
    {
        $msisdn = $this->normalizedMsisdn($msisdn);

        if ($msisdn === $merchant->msisdn) {
            throw ValidationException::withMessages([
                'customerMsisdn' => 'That is your own number.',
            ]);
        }

        return DB::transaction(function () use ($merchant, $name, $msisdn, $limitAmount, $settlementDay): Tab {
            $customer = $this->resolveCustomer($name, $msisdn);

            $tab = Tab::query()->firstOrCreate(
                [
                    'merchant_id' => $merchant->id,
                    'customer_id' => $customer->id,
                ],
            );

            if ($tab->status === TabStatus::Closed) {
                $tab->status = TabStatus::Active;
                $tab->save();
            }

            if ($limitAmount !== null && $settlementDay !== null) {
                return $this->proposeTabTerms->handle($tab, $merchant, $limitAmount, $settlementDay);
            }

            return $tab;
        });
    }

    protected function resolveCustomer(string $name, string $msisdn): User
    {
        try {
            return User::query()->firstOrCreate(
                ['msisdn' => $msisdn],
                ['name' => $name],
            );
        } catch (UniqueConstraintViolationException) {
            /**
             * Two merchants typed the same new number at the same moment. The
             * unique index on msisdn is the source of truth; the loser of the
             * race looks the winner up.
             */
            return User::query()->where('msisdn', $msisdn)->firstOrFail();
        }
    }

    protected function normalizedMsisdn(string $value): string
    {
        $msisdn = Msisdn::tryNormalize($value);

        if ($msisdn === null) {
            throw ValidationException::withMessages([
                'customerMsisdn' => 'Enter a mobile number, including the country code if it is not South African.',
            ]);
        }

        return $msisdn;
    }
}
