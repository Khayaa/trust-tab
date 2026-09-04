<?php

use App\Enums\TabEntryStatus;
use App\Models\PaymentRequest;
use App\Models\Tab;
use App\Models\TabEntry;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

it('only counts confirmed unsettled entries toward the balance', function () {
    $tab = Tab::factory()->create();

    TabEntry::factory()->for($tab)->confirmed()->amount('90.00')->create();
    TabEntry::factory()->for($tab)->confirmed()->amount('50.00')->create();
    TabEntry::factory()->for($tab)->amount('75.00')->create();
    TabEntry::factory()->for($tab)->disputed()->amount('20.00')->create();
    TabEntry::factory()->for($tab)->withdrawn()->amount('30.00')->create();
    TabEntry::factory()->for($tab)->settled()->amount('60.00')->create();

    expect($tab->outstandingBalance())->toBe('140.00');
});

it('sums loaded outstanding entries instead of querying again', function () {
    $tab = Tab::factory()->create();

    TabEntry::factory()->for($tab)->confirmed()->amount('90.00')->create();
    TabEntry::factory()->for($tab)->amount('75.00')->create();

    $tab->load(['outstandingEntries', 'paymentRequests']);

    DB::enableQueryLog();
    DB::flushQueryLog();

    expect($tab->outstandingBalance())->toBe('90.00')
        ->and(DB::getQueryLog())->toBeEmpty();
});

it('subtracts unallocated payment credits from the outstanding without rewriting lines', function () {
    $tab = Tab::factory()->create();
    $entry = TabEntry::factory()->for($tab)->confirmed()->amount('90.00')->create();

    PaymentRequest::factory()->for($tab)->successful()->create([
        'amount' => '50.00',
        'unallocated_amount' => '50.00',
    ]);

    expect($tab->outstandingBalance())->toBe('40.00')
        ->and($entry->fresh()->amount)->toBe('90.00')
        ->and($entry->fresh()->status)->toBe(TabEntryStatus::Confirmed);
});

it('reports a zero balance when nothing is confirmed', function () {
    $tab = Tab::factory()->create();

    TabEntry::factory()->for($tab)->amount('75.00')->create();

    expect($tab->outstandingBalance())->toBe('0.00');
});

it('keeps the balance exact over amounts that lose precision as floats', function () {
    $tab = Tab::factory()->create();

    foreach (['0.10', '0.20', '0.30', '10.05', '0.35'] as $amount) {
        TabEntry::factory()->for($tab)->confirmed()->amount($amount)->create();
    }

    expect($tab->outstandingBalance())->toBe('11.00');
});

it('knows when the customer has an entry waiting on them', function () {
    $tab = Tab::factory()->create();

    expect($tab->hasEntryAwaiting())->toBeFalse();

    TabEntry::factory()->for($tab)->create();

    expect($tab->fresh()->hasEntryAwaiting())->toBeTrue();
});

it('casts amounts and statuses to their domain types', function () {
    $entry = TabEntry::factory()->confirmed()->amount('12.5')->create();

    expect($entry->status)->toBe(TabEntryStatus::Confirmed)
        ->and($entry->amount)->toBe('12.50')
        ->and($entry->confirmed_at)->toBeInstanceOf(Carbon::class);
});

it('attributes an entry to the merchant who created it', function () {
    $tab = Tab::factory()->create();
    $entry = TabEntry::factory()->for($tab)->create();

    expect($entry->created_by)->toBe($tab->merchant_id);
});

it('prevents a second tab between the same merchant and customer', function () {
    $merchant = User::factory()->merchant()->create();
    $customer = User::factory()->create();

    Tab::factory()->between($merchant, $customer)->create();

    expect(fn () => Tab::factory()->between($merchant, $customer)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('scopes tabs to a participant on either side', function () {
    $merchant = User::factory()->merchant()->create();
    $customer = User::factory()->create();
    $stranger = User::factory()->create();

    $tab = Tab::factory()->between($merchant, $customer)->create();
    Tab::factory()->create();

    expect(Tab::forParticipant($merchant)->pluck('id')->all())->toBe([$tab->id])
        ->and(Tab::forParticipant($customer)->pluck('id')->all())->toBe([$tab->id])
        ->and(Tab::forParticipant($stranger)->count())->toBe(0);
});
