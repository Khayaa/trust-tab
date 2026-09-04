<?php

use App\Models\Checkout;
use App\Models\Tab;
use App\Models\TabEntry;
use App\Models\User;

beforeEach(function () {
    $this->merchant = User::factory()->merchant()->create();
    $this->customer = User::factory()->create();
    $this->stranger = User::factory()->create();
    $this->tab = Tab::factory()->between($this->merchant, $this->customer)->create();
});

it('hides a tab from everyone except its two participants', function () {
    expect($this->merchant->can('view', $this->tab))->toBeTrue()
        ->and($this->customer->can('view', $this->tab))->toBeTrue()
        ->and($this->stranger->can('view', $this->tab))->toBeFalse();
});

it('only lets the merchant add entries once the customer has agreed the tab', function () {
    expect($this->merchant->can('addEntry', $this->tab))->toBeTrue()
        ->and($this->customer->can('addEntry', $this->tab))->toBeFalse()
        ->and($this->stranger->can('addEntry', $this->tab))->toBeFalse();
});

it('lets only the merchant propose terms, and only the customer accept them', function () {
    expect($this->customer->can('acceptAgreement', $this->tab))->toBeFalse();

    $this->tab->agreement_accepted_at = null;
    $this->tab->save();

    expect($this->merchant->can('proposeTerms', $this->tab))->toBeTrue()
        ->and($this->customer->can('proposeTerms', $this->tab))->toBeFalse()
        ->and($this->stranger->can('proposeTerms', $this->tab))->toBeFalse()
        ->and($this->customer->can('acceptAgreement', $this->tab->fresh()))->toBeTrue()
        ->and($this->merchant->can('acceptAgreement', $this->tab->fresh()))->toBeFalse()
        ->and($this->stranger->can('acceptAgreement', $this->tab->fresh()))->toBeFalse();
});

it('stops the merchant adding entries before the customer agrees', function () {
    $this->tab->agreement_accepted_at = null;
    $this->tab->save();

    expect($this->merchant->can('addEntry', $this->tab->fresh()))->toBeFalse();
});

it('stops entries being added to a closed tab', function () {
    $closed = Tab::factory()->closed()->create(['merchant_id' => $this->merchant->id]);

    expect($this->merchant->can('addEntry', $closed))->toBeFalse();
});

it('only lets the customer confirm or dispute', function () {
    $entry = TabEntry::factory()->for($this->tab)->create();

    expect($this->customer->can('confirm', $entry))->toBeTrue()
        ->and($this->customer->can('dispute', $entry))->toBeTrue()
        ->and($this->merchant->can('confirm', $entry))->toBeFalse()
        ->and($this->merchant->can('dispute', $entry))->toBeFalse()
        ->and($this->stranger->can('confirm', $entry))->toBeFalse();
});

it('only lets the merchant withdraw, and only once disputed', function () {
    $pending = TabEntry::factory()->for($this->tab)->create();
    $disputed = TabEntry::factory()->for($this->tab)->disputed()->create();

    expect($this->merchant->can('withdraw', $disputed))->toBeTrue()
        ->and($this->merchant->can('withdraw', $pending))->toBeFalse()
        ->and($this->customer->can('withdraw', $disputed))->toBeFalse()
        ->and($this->merchant->can('correct', $disputed))->toBeTrue()
        ->and($this->merchant->can('correct', $pending))->toBeFalse()
        ->and($this->customer->can('correct', $disputed))->toBeFalse();
});

it('only lets the customer confirm every waiting line at once', function () {
    expect($this->customer->can('confirmPending', $this->tab))->toBeTrue()
        ->and($this->merchant->can('confirmPending', $this->tab))->toBeFalse()
        ->and($this->stranger->can('confirmPending', $this->tab))->toBeFalse();
});

it('offers settlement only to the customer and only when money is owed', function () {
    expect($this->customer->can('settle', $this->tab))->toBeFalse();

    TabEntry::factory()->for($this->tab)->confirmed()->amount('45.50')->create();

    expect($this->customer->fresh()->can('settle', $this->tab->fresh()))->toBeTrue()
        ->and($this->merchant->can('settle', $this->tab->fresh()))->toBeFalse();
});

it('does not offer settlement when the only entries are unconfirmed', function () {
    TabEntry::factory()->for($this->tab)->amount('45.50')->create();
    TabEntry::factory()->for($this->tab)->disputed()->amount('20.00')->create();

    expect($this->customer->can('settle', $this->tab))->toBeFalse();
});

it('lets only the merchant run the till on an active tab', function () {
    expect($this->merchant->can('runTill', $this->tab))->toBeTrue()
        ->and($this->customer->can('runTill', $this->tab))->toBeFalse()
        ->and($this->stranger->can('runTill', $this->tab))->toBeFalse();

    $this->tab->agreement_accepted_at = null;
    $this->tab->save();

    expect($this->merchant->can('runTill', $this->tab->fresh()))->toBeTrue();

    $closed = Tab::factory()->closed()->create(['merchant_id' => $this->merchant->id]);

    expect($this->merchant->can('runTill', $closed))->toBeFalse();
});

it('lets only the merchant put an open basket on an agreed tab', function () {
    $open = Checkout::factory()->forTab($this->tab)->create();

    expect($this->merchant->can('putOnTab', $open))->toBeTrue()
        ->and($this->customer->can('putOnTab', $open))->toBeFalse()
        ->and($this->stranger->can('putOnTab', $open))->toBeFalse();

    $this->tab->agreement_accepted_at = null;
    $this->tab->save();
    $open->unsetRelation('tab');

    expect($this->merchant->can('putOnTab', $open->fresh()))->toBeFalse();
});

it('lets only the customer confirm or dispute a basket waiting on the tab', function () {
    $checkout = Checkout::factory()->forTab($this->tab)->awaitingConfirmation()->create();

    expect($this->customer->can('confirm', $checkout))->toBeTrue()
        ->and($this->customer->can('dispute', $checkout))->toBeTrue()
        ->and($this->merchant->can('confirm', $checkout))->toBeFalse()
        ->and($this->merchant->can('dispute', $checkout))->toBeFalse()
        ->and($this->stranger->can('confirm', $checkout))->toBeFalse()
        ->and($this->merchant->can('cancel', $checkout))->toBeTrue()
        ->and($this->customer->can('cancel', $checkout))->toBeFalse();
});
