<?php

use App\Actions\ApplyMomoResult;
use App\Actions\RequestTabSettlement;
use App\Enums\TabEntryStatus;
use App\Models\Tab;
use App\Models\TabEntry;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->merchant = User::factory()->merchant()->create();
    $this->customer = User::factory()->create();
    $this->tab = Tab::factory()->between($this->merchant, $this->customer)->create();

    $this->fakeMomoPending = function () {
        Http::fake([
            '*/collection/token/' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
            '*/collection/v1_0/requesttopay' => Http::response(status: 202),
        ]);
    };

    $this->confirmSuccessful = function ($paymentRequest) {
        Http::fake([
            '*/collection/token/' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
            '*/collection/v1_0/requesttopay/*' => Http::response(['status' => 'SUCCESSFUL']),
        ]);

        return app(ApplyMomoResult::class)->handle($paymentRequest);
    };
});

it('leaves a remainder as credit instead of splitting the oldest line', function () {
    ($this->fakeMomoPending)();

    $bread = TabEntry::factory()->for($this->tab)->confirmed()->amount('90.00')->create(['created_at' => now()->subMinute()]);
    $airtime = TabEntry::factory()->for($this->tab)->confirmed()->amount('50.00')->create();

    $payment = app(RequestTabSettlement::class)->handle($this->tab, '50.00');
    ($this->confirmSuccessful)($payment);

    expect($bread->fresh()->status)->toBe(TabEntryStatus::Confirmed)
        ->and($bread->fresh()->amount)->toBe('90.00')
        ->and($airtime->fresh()->status)->toBe(TabEntryStatus::Confirmed)
        ->and($payment->fresh()->unallocated_amount)->toBe('50.00')
        ->and($this->tab->outstandingBalance())->toBe('90.00');
});

it('settles the oldest line once later payments cover it in full', function () {
    ($this->fakeMomoPending)();

    $bread = TabEntry::factory()->for($this->tab)->confirmed()->amount('90.00')->create(['created_at' => now()->subMinute()]);
    $airtime = TabEntry::factory()->for($this->tab)->confirmed()->amount('50.00')->create();

    $first = app(RequestTabSettlement::class)->handle($this->tab, '50.00');
    ($this->confirmSuccessful)($first);

    ($this->fakeMomoPending)();
    $second = app(RequestTabSettlement::class)->handle($this->tab, '50.00');
    ($this->confirmSuccessful)($second);

    expect($bread->fresh()->status)->toBe(TabEntryStatus::Settled)
        ->and($bread->fresh()->amount)->toBe('90.00')
        ->and($airtime->fresh()->status)->toBe(TabEntryStatus::Confirmed)
        ->and($second->fresh()->unallocated_amount)->toBe('10.00')
        ->and($first->fresh()->unallocated_amount)->toBe('0.00')
        ->and($this->tab->outstandingBalance())->toBe('40.00');
});

it('does not sweep an entry confirmed after the payment was raised', function () {
    ($this->fakeMomoPending)();

    $captured = TabEntry::factory()->for($this->tab)->confirmed()->amount('90.00')->create();

    $payment = app(RequestTabSettlement::class)->handle($this->tab, '50.00');

    $later = TabEntry::factory()->for($this->tab)->confirmed()->amount('30.00')->create();

    ($this->confirmSuccessful)($payment);

    expect($captured->fresh()->status)->toBe(TabEntryStatus::Confirmed)
        ->and($later->fresh()->status)->toBe(TabEntryStatus::Confirmed)
        ->and($this->tab->outstandingBalance())->toBe('70.00');
});

it('still settles every snapshotted line when the customer pays the full outstanding', function () {
    ($this->fakeMomoPending)();

    $bread = TabEntry::factory()->for($this->tab)->confirmed()->amount('90.00')->create(['created_at' => now()->subMinute()]);
    $airtime = TabEntry::factory()->for($this->tab)->confirmed()->amount('50.00')->create();

    $payment = app(RequestTabSettlement::class)->handle($this->tab);
    ($this->confirmSuccessful)($payment);

    expect($bread->fresh()->status)->toBe(TabEntryStatus::Settled)
        ->and($airtime->fresh()->status)->toBe(TabEntryStatus::Settled)
        ->and($payment->fresh()->unallocated_amount)->toBe('0.00')
        ->and($this->tab->outstandingBalance())->toBe('0.00');
});

it('refuses an amount greater than the current outstanding', function () {
    Http::fake();

    TabEntry::factory()->for($this->tab)->confirmed()->amount('90.00')->create();

    expect(fn () => app(RequestTabSettlement::class)->handle($this->tab, '90.01'))
        ->toThrow(ValidationException::class);

    expect($this->tab->paymentRequests()->count())->toBe(0)
        ->and($this->tab->outstandingBalance())->toBe('90.00');
});

it('lets the customer pay a chosen amount from the till', function () {
    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
        '*/collection/v1_0/requesttopay' => Http::response(status: 202),
    ]);

    TabEntry::factory()->for($this->tab)->confirmed()->amount('90.00')->create();
    TabEntry::factory()->for($this->tab)->confirmed()->amount('50.00')->create();

    Livewire::actingAs($this->customer)
        ->test('pages::show-tab', ['tab' => $this->tab])
        ->set('settlementAmount', '50.00')
        ->call('settle')
        ->assertHasNoErrors()
        ->assertSee('Waiting for MoMo');

    expect($this->tab->paymentRequests()->sole()->amount)->toBe('50.00')
        ->and($this->tab->outstandingBalance())->toBe('140.00');
});
