<?php

use App\Actions\AddTabEntry;
use App\Actions\ApplyMomoResult;
use App\Actions\ConfirmTabEntry;
use App\Enums\TabEntryStatus;
use App\Events\TabUpdated;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestEntry;
use App\Models\Tab;
use App\Models\TabEntry;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->merchant = User::factory()->merchant()->create();
    $this->customer = User::factory()->create(['msisdn' => '46733123453']);
    $this->tab = Tab::factory()->between($this->merchant, $this->customer)->create();
});

it('tells the tab when the merchant adds an item', function () {
    Event::fake([TabUpdated::class]);

    app(AddTabEntry::class)->handle($this->tab, $this->merchant, [
        'description' => 'Bread and milk',
        'amount' => '45.50',
    ]);

    Event::assertDispatched(TabUpdated::class, fn (TabUpdated $event) => $event->tabId === $this->tab->id);
});

it('tells the tab when the customer confirms an item', function () {
    $entry = TabEntry::factory()->for($this->tab)->amount('45.50')->create();

    Event::fake([TabUpdated::class]);

    app(ConfirmTabEntry::class)->handle($entry);

    Event::assertDispatched(TabUpdated::class, fn (TabUpdated $event) => $event->tabId === $this->tab->id);
});

it('tells the tab when momo settles a payment nobody was watching', function () {
    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
        '*/collection/v1_0/requesttopay/*' => Http::response(['status' => 'SUCCESSFUL']),
    ]);

    $entry = TabEntry::factory()->for($this->tab)->confirmed()->amount('45.50')->create();
    $paymentRequest = PaymentRequest::factory()->for($this->tab)->pending()->create(['amount' => '45.50']);

    PaymentRequestEntry::factory()->create([
        'payment_request_id' => $paymentRequest->id,
        'tab_entry_id' => $entry->id,
        'amount' => $entry->amount,
    ]);

    Event::fake([TabUpdated::class]);

    app(ApplyMomoResult::class)->handle($paymentRequest);

    Event::assertDispatched(TabUpdated::class, fn (TabUpdated $event) => $event->tabId === $this->tab->id);
});

it('waits for the transaction to commit, so a listener never reads a half-settled tab', function () {
    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
        '*/collection/v1_0/requesttopay/*' => Http::response(['status' => 'SUCCESSFUL']),
    ]);

    $entry = TabEntry::factory()->for($this->tab)->confirmed()->amount('45.50')->create();
    $paymentRequest = PaymentRequest::factory()->for($this->tab)->pending()->create(['amount' => '45.50']);

    PaymentRequestEntry::factory()->create([
        'payment_request_id' => $paymentRequest->id,
        'tab_entry_id' => $entry->id,
        'amount' => $entry->amount,
    ]);

    /*
     * The payment request is saved before the entries it settles, both inside
     * one transaction. A listener that fired on that save would see the tab
     * still owing money.
     */
    $balanceWhenHeard = null;

    Event::listen(TabUpdated::class, function () use (&$balanceWhenHeard) {
        $balanceWhenHeard = $this->tab->outstandingBalance();
    });

    app(ApplyMomoResult::class)->handle($paymentRequest);

    expect($balanceWhenHeard)->toBe('0.00')
        ->and($entry->fresh()->status)->toBe(TabEntryStatus::Settled);
});

it('records the item even when the websocket server is down', function () {
    /*
     * TabUpdated broadcasts inline, so an unreachable Reverb throws. Live
     * updates are a courtesy and must never take a shopkeeper's till with them.
     */
    Event::listen(TabUpdated::class, function () {
        throw new BroadcastException('Connection refused');
    });

    $entry = app(AddTabEntry::class)->handle($this->tab, $this->merchant, [
        'description' => 'Bread and milk',
        'amount' => '45.50',
    ]);

    expect($entry->exists)->toBeTrue()
        ->and($this->tab->entries()->count())->toBe(1);
});

it('settles the payment even when the websocket server is down', function () {
    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
        '*/collection/v1_0/requesttopay/*' => Http::response(['status' => 'SUCCESSFUL']),
    ]);

    $entry = TabEntry::factory()->for($this->tab)->confirmed()->amount('45.50')->create();
    $paymentRequest = PaymentRequest::factory()->for($this->tab)->pending()->create(['amount' => '45.50']);

    PaymentRequestEntry::factory()->create([
        'payment_request_id' => $paymentRequest->id,
        'tab_entry_id' => $entry->id,
        'amount' => $entry->amount,
    ]);

    Event::listen(TabUpdated::class, function () {
        throw new BroadcastException('Connection refused');
    });

    app(ApplyMomoResult::class)->handle($paymentRequest);

    expect($entry->fresh()->status)->toBe(TabEntryStatus::Settled)
        ->and($this->tab->outstandingBalance())->toBe('0.00');
});

it('broadcasts on a private channel carrying nothing but the tab id', function () {
    $event = new TabUpdated($this->tab->id);

    expect($event->broadcastOn())->toEqual([new PrivateChannel("tabs.{$this->tab->id}")])
        ->and($event->broadcastWith())->toBe(['tabId' => $this->tab->id]);
});

it('lets both people on a tab listen to it', function () {
    foreach ([$this->merchant, $this->customer] as $participant) {
        expect($participant->can('view', $this->tab))->toBeTrue();
    }
});

it('keeps everyone else off the tab channel', function () {
    $stranger = User::factory()->create();

    expect($stranger->can('view', $this->tab))->toBeFalse();
});
