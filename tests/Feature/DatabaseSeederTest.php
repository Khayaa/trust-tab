<?php

use App\Enums\TabEntryStatus;
use App\Models\Product;
use App\Models\Tab;
use App\Models\TabEntry;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates the demo shop, customer, and a tab with an outstanding balance', function () {
    $this->seed();

    $merchant = User::query()->where('msisdn', '27821234567')->first();
    $customer = User::query()->where('msisdn', '27723041887')->first();

    expect($merchant)->not->toBeNull()
        ->and($merchant->name)->toBe('Nandi Dlamini')
        ->and($merchant->email)->toBe('nandi@trusttab.test')
        ->and(Hash::check('password', $merchant->password))->toBeTrue()
        ->and($merchant->merchantProfile?->business_name)->toBe('Mama Nandi Spaza');

    expect($customer)->not->toBeNull()
        ->and($customer->name)->toBe('Sipho Khumalo');

    $tab = Tab::query()
        ->where('merchant_id', $merchant->id)
        ->where('customer_id', $customer->id)
        ->first();

    expect($tab)->not->toBeNull()
        ->and($tab->outstandingBalance())->toBe('3.50')
        ->and($tab->hasEntryAwaiting())->toBeTrue()
        ->and($tab->limit_amount)->toBe('500.00')
        ->and($tab->settlement_day)->toBe(25)
        ->and($tab->hasAcceptedAgreement())->toBeTrue()
        ->and($tab->availableAmount())->toBe('496.50');

    $shelf = Product::query()->where('merchant_id', $merchant->id)->get();

    expect($shelf)->toHaveCount(5)
        ->and(Product::query()->where('name', 'Washing powder')->sole()->isLowStock())->toBeTrue();

    $shelf->each(fn (Product $product) => expect(bccomp($product->selling_price, '5.00', 2))->toBe(-1));

    $this->assertDatabaseHas('tab_entries', [
        'tab_id' => $tab->id,
        'description' => 'Washing powder',
        'amount' => '3.00',
        'status' => TabEntryStatus::PendingConfirmation->value,
    ]);
});

it('rewrites catalogue prices so a collection demo stays cheap', function () {
    $this->seed();

    $bread = Product::query()->where('name', 'Bread')->sole();
    $bread->update(['selling_price' => '18.00']);

    $this->seed();

    expect($bread->fresh()->selling_price)->toBe('1.50');
});

it('repoints sipho at the demo number without duplicating the tab', function () {
    $this->seed();

    $customer = User::query()->where('email', 'sipho@trusttab.test')->sole();
    $customer->update(['msisdn' => '27819999999']);

    $this->seed();

    expect($customer->fresh()->msisdn)->toBe('27723041887')
        ->and(Tab::query()->count())->toBe(1)
        ->and(TabEntry::query()->count())->toBe(2)
        ->and(Product::query()->count())->toBe(5);
});
