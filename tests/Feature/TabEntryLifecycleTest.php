<?php

use App\Actions\AddTabEntry;
use App\Actions\ConfirmTabEntry;
use App\Actions\DisputeTabEntry;
use App\Actions\WithdrawTabEntry;
use App\Enums\TabEntryStatus;
use App\Exceptions\InvalidTabEntryTransition;
use App\Models\Tab;
use App\Models\TabEntry;
use App\Models\User;

beforeEach(function () {
    $this->merchant = User::factory()->merchant()->create();
    $this->customer = User::factory()->create();
    $this->tab = Tab::factory()->between($this->merchant, $this->customer)->create();
});

it('records a new entry as unconfirmed and owed by nobody yet', function () {
    $entry = app(AddTabEntry::class)->handle($this->tab, $this->merchant, [
        'description' => 'Bread and milk',
        'amount' => '45.50',
    ]);

    expect($entry->status)->toBe(TabEntryStatus::PendingConfirmation)
        ->and($entry->created_by)->toBe($this->merchant->id)
        ->and($entry->amount)->toBe('45.50')
        ->and($this->tab->outstandingBalance())->toBe('0.00');
});

it('adds the amount to the balance once the customer confirms', function () {
    $entry = TabEntry::factory()->for($this->tab)->amount('45.50')->create();

    $confirmed = app(ConfirmTabEntry::class)->handle($entry);

    expect($confirmed->status)->toBe(TabEntryStatus::Confirmed)
        ->and($confirmed->confirmed_at)->not->toBeNull()
        ->and($this->tab->outstandingBalance())->toBe('45.50');
});

it('keeps a disputed amount off the balance', function () {
    $entry = TabEntry::factory()->for($this->tab)->amount('45.50')->create();

    $disputed = app(DisputeTabEntry::class)->handle($entry, 'I did not take this');

    expect($disputed->status)->toBe(TabEntryStatus::Disputed)
        ->and($disputed->note)->toBe('I did not take this')
        ->and($this->tab->outstandingBalance())->toBe('0.00');
});

it('lets the merchant withdraw a disputed entry so the dispute can end', function () {
    $entry = TabEntry::factory()->for($this->tab)->disputed()->amount('45.50')->create();

    $withdrawn = app(WithdrawTabEntry::class)->handle($entry);

    expect($withdrawn->status)->toBe(TabEntryStatus::Withdrawn)
        ->and($withdrawn->withdrawn_at)->not->toBeNull()
        ->and($this->tab->outstandingBalance())->toBe('0.00');
});

it('refuses to confirm the same entry twice', function () {
    $entry = TabEntry::factory()->for($this->tab)->amount('45.50')->create();

    app(ConfirmTabEntry::class)->handle($entry);

    expect(fn () => app(ConfirmTabEntry::class)->handle($entry->fresh()))
        ->toThrow(InvalidTabEntryTransition::class);

    expect($this->tab->outstandingBalance())->toBe('45.50');
});

it('refuses to dispute an entry that is already confirmed', function () {
    $entry = TabEntry::factory()->for($this->tab)->confirmed()->create();

    expect(fn () => app(DisputeTabEntry::class)->handle($entry))
        ->toThrow(InvalidTabEntryTransition::class);
});

it('refuses to withdraw an entry that was never disputed', function () {
    $entry = TabEntry::factory()->for($this->tab)->confirmed()->create();

    expect(fn () => app(WithdrawTabEntry::class)->handle($entry))
        ->toThrow(InvalidTabEntryTransition::class);
});

it('refuses to confirm an entry the merchant already withdrew', function () {
    $entry = TabEntry::factory()->for($this->tab)->withdrawn()->create();

    expect(fn () => app(ConfirmTabEntry::class)->handle($entry))
        ->toThrow(InvalidTabEntryTransition::class);
});

it('moves the balance across a full add, confirm and settle-free sequence', function () {
    $bread = app(AddTabEntry::class)->handle($this->tab, $this->merchant, [
        'description' => 'Bread and milk',
        'amount' => '90.00',
    ]);

    $airtime = app(AddTabEntry::class)->handle($this->tab, $this->merchant, [
        'description' => 'Airtime',
        'amount' => '50.00',
    ]);

    $soap = app(AddTabEntry::class)->handle($this->tab, $this->merchant, [
        'description' => 'Washing powder',
        'amount' => '75.00',
    ]);

    expect($this->tab->outstandingBalance())->toBe('0.00');

    app(ConfirmTabEntry::class)->handle($bread);
    app(ConfirmTabEntry::class)->handle($airtime);

    expect($this->tab->outstandingBalance())->toBe('140.00');

    app(DisputeTabEntry::class)->handle($soap);

    expect($this->tab->outstandingBalance())->toBe('140.00');
});
