<?php

namespace Database\Seeders;

use App\Enums\TabEntryStatus;
use App\Models\Product;
use App\Models\Tab;
use App\Models\TabEntry;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the demo scenario: one spaza shop owner, one customer, and a tab
     * with an outstanding balance plus an entry still awaiting confirmation.
     *
     * Factories are not used here. fakerphp/faker is require-dev, so a
     * production install (composer --no-dev) has no fake() helper.
     *
     * Users are updateOrCreate'd by email so re-seed can refresh demo
     * numbers without wiping the tab. Names avoid apostrophes, which
     * MoMo rejects in payerMessage.
     *
     * Catalogue prices stay under R5 so a sandbox Collection demo cannot
     * charge grocery amounts if someone points live keys at it.
     */
    public function run(): void
    {
        $merchant = User::query()->updateOrCreate(
            ['email' => 'nandi@trusttab.test'],
            [
                'name' => 'Nandi Dlamini',
                'msisdn' => '27821234567',
                'email_verified_at' => now(),
                'password' => 'password',
            ],
        );

        $merchant->merchantProfile()->firstOrCreate(
            [],
            ['business_name' => 'Mama Nandi Spaza'],
        );

        $customer = User::query()->updateOrCreate(
            ['email' => 'sipho@trusttab.test'],
            [
                'name' => 'Sipho Khumalo',
                'msisdn' => '27723041887',
                'email_verified_at' => now(),
                'password' => 'password',
            ],
        );

        $tab = Tab::query()->firstOrCreate([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
        ]);

        $tab->limit_amount = '500.00';
        $tab->settlement_day = 25;
        $tab->agreement_accepted_at ??= now();
        $tab->save();

        $this->seedProducts($merchant);

        if ($tab->entries()->exists()) {
            return;
        }

        TabEntry::query()->create([
            'tab_id' => $tab->id,
            'created_by' => $merchant->id,
            'description' => 'Bread and milk',
            'amount' => '3.50',
            'status' => TabEntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        TabEntry::query()->create([
            'tab_id' => $tab->id,
            'created_by' => $merchant->id,
            'description' => 'Washing powder',
            'amount' => '3.00',
        ]);
    }

    /**
     * Demo shelf for the till. firstOrCreate keeps stock a merchant already
     * counted. selling_price is always rewritten so a re-seed cannot
     * leave grocery amounts on a Collection wallet.
     */
    protected function seedProducts(User $merchant): void
    {
        foreach ([
            ['name' => 'Bread', 'barcode' => '6001234000018', 'selling_price' => '1.50', 'stock_quantity' => 40, 'low_stock_threshold' => 8],
            ['name' => 'Milk', 'barcode' => '6001234000025', 'selling_price' => '2.00', 'stock_quantity' => 24, 'low_stock_threshold' => 6],
            ['name' => 'Eggs', 'barcode' => '6001234000032', 'selling_price' => '2.50', 'stock_quantity' => 18, 'low_stock_threshold' => 6],
            ['name' => 'Maize meal', 'barcode' => '6001234000049', 'selling_price' => '3.00', 'stock_quantity' => 12, 'low_stock_threshold' => 4],
            ['name' => 'Washing powder', 'barcode' => '6001234000063', 'selling_price' => '3.00', 'stock_quantity' => 5, 'low_stock_threshold' => 8],
        ] as $attributes) {
            $product = Product::query()->firstOrCreate(
                [
                    'merchant_id' => $merchant->id,
                    'name' => $attributes['name'],
                ],
                [
                    'barcode' => $attributes['barcode'],
                    'selling_price' => $attributes['selling_price'],
                    'stock_quantity' => $attributes['stock_quantity'],
                    'low_stock_threshold' => $attributes['low_stock_threshold'],
                    'is_active' => true,
                ],
            );

            $product->selling_price = $attributes['selling_price'];
            $product->save();
        }
    }
}
