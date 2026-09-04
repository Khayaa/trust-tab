<?php

use App\Actions\ApplyMomoResult;
use App\Enums\PaymentRequestStatus;
use App\Enums\TabEntryStatus;
use App\Jobs\ProcessMomoCallback;
use App\Models\MomoWebhookEvent;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestEntry;
use App\Models\Tab;
use App\Models\TabEntry;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->merchant = User::factory()->merchant()->create();
    $this->customer = User::factory()->create(['msisdn' => '46733123453']);
    $this->tab = Tab::factory()->between($this->merchant, $this->customer)->create();

    $this->paymentRequest = PaymentRequest::factory()->for($this->tab)->pending()->create([
        'amount' => '64.50',
    ]);

    $this->fakeStatus = function (string $status, ?string $reason = null) {
        Http::fake([
            '*/collection/token/' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
            '*/collection/v1_0/requesttopay/*' => Http::response(array_filter([
                'status' => $status,
                'reason' => $reason,
                'amount' => '64.50',
            ])),
        ]);
    };

    $this->snapshot = function (TabEntry ...$entries) {
        foreach ($entries as $entry) {
            PaymentRequestEntry::factory()->create([
                'payment_request_id' => $this->paymentRequest->id,
                'tab_entry_id' => $entry->id,
                'amount' => $entry->amount,
            ]);
        }
    };
});

it('stores only the status from the callback body', function () {
    Queue::fake();

    $this->putJson('/momo/callback', [
        'status' => 'SUCCESSFUL',
        'payer' => ['partyId' => '27723041887'],
        'financialTransactionId' => 'should-not-be-kept',
    ], [
        'X-Reference-Id' => $this->paymentRequest->reference_id,
    ])->assertOk();

    expect(MomoWebhookEvent::where('reference_id', $this->paymentRequest->reference_id)->sole()->payload)
        ->toBe(['status' => 'SUCCESSFUL']);
});

it('accepts the callback and asks momo itself what happened', function () {
    Queue::fake();

    $this->putJson('/momo/callback', ['status' => 'SUCCESSFUL'], [
        'X-Reference-Id' => $this->paymentRequest->reference_id,
    ])->assertOk();

    Queue::assertPushed(ProcessMomoCallback::class, fn (ProcessMomoCallback $job) => $job->referenceId === $this->paymentRequest->reference_id);

    expect(MomoWebhookEvent::where('reference_id', $this->paymentRequest->reference_id)->exists())->toBeTrue();
});

it('never settles anything on the strength of the callback body alone', function () {
    ($this->fakeStatus)('PENDING');

    $entry = TabEntry::factory()->for($this->tab)->confirmed()->amount('64.50')->create();
    ($this->snapshot)($entry);

    $this->putJson('/momo/callback', ['status' => 'SUCCESSFUL'], [
        'X-Reference-Id' => $this->paymentRequest->reference_id,
    ])->assertOk();

    expect($this->paymentRequest->fresh()->status)->toBe(PaymentRequestStatus::Pending)
        ->and($entry->fresh()->status)->toBe(TabEntryStatus::Confirmed);
});

it('ignores a replayed callback so the customer is never charged twice', function () {
    Queue::fake();

    foreach (range(1, 3) as $ignored) {
        $this->putJson('/momo/callback', [], [
            'X-Reference-Id' => $this->paymentRequest->reference_id,
        ])->assertOk();
    }

    Queue::assertPushed(ProcessMomoCallback::class, 1);

    expect(MomoWebhookEvent::where('reference_id', $this->paymentRequest->reference_id)->count())->toBe(1);
});

it('answers ok for a reference it does not recognise, because momo never retries', function () {
    Queue::fake();

    $this->putJson('/momo/callback', [], ['X-Reference-Id' => 'not-ours'])->assertOk();

    Queue::assertPushed(ProcessMomoCallback::class, 1);
});

it('settles after a callback once momo confirms, and writes a log for the cloud', function () {
    Event::fake([MessageLogged::class]);
    ($this->fakeStatus)('SUCCESSFUL');

    $entry = TabEntry::factory()->for($this->tab)->confirmed()->amount('64.50')->create();
    ($this->snapshot)($entry);

    $this->putJson('/momo/callback', ['status' => 'FAILED'], [
        'X-Reference-Id' => $this->paymentRequest->reference_id,
    ])->assertOk();

    expect($this->paymentRequest->fresh()->status)->toBe(PaymentRequestStatus::Successful)
        ->and($entry->fresh()->status)->toBe(TabEntryStatus::Settled);

    Event::assertDispatched(MessageLogged::class, fn (MessageLogged $log) => $log->message === 'MoMo callback received'
        && $log->context['reference_id'] === $this->paymentRequest->reference_id
        && $log->context['dispatched'] === true);

    Event::assertDispatched(MessageLogged::class, fn (MessageLogged $log) => $log->message === 'MoMo callback applied'
        && $log->context['status'] === 'successful');
});

it('settles the entries the payment captured once momo confirms the money moved', function () {
    ($this->fakeStatus)('SUCCESSFUL');

    $entry = TabEntry::factory()->for($this->tab)->confirmed()->amount('64.50')->create();
    ($this->snapshot)($entry);

    app(ApplyMomoResult::class)->handle($this->paymentRequest);

    expect($this->paymentRequest->fresh()->status)->toBe(PaymentRequestStatus::Successful)
        ->and($entry->fresh()->status)->toBe(TabEntryStatus::Settled)
        ->and($entry->fresh()->settled_at)->not->toBeNull()
        ->and($this->tab->outstandingBalance())->toBe('0.00');
});

it('leaves an entry confirmed after the payment was raised out of the settlement', function () {
    ($this->fakeStatus)('SUCCESSFUL');

    $captured = TabEntry::factory()->for($this->tab)->confirmed()->amount('64.50')->create();
    ($this->snapshot)($captured);

    $confirmedLater = TabEntry::factory()->for($this->tab)->confirmed()->amount('30.00')->create();

    app(ApplyMomoResult::class)->handle($this->paymentRequest);

    expect($captured->fresh()->status)->toBe(TabEntryStatus::Settled)
        ->and($confirmedLater->fresh()->status)->toBe(TabEntryStatus::Confirmed)
        ->and($this->tab->outstandingBalance())->toBe('30.00');
});

it('leaves the debt standing when the customer rejects the prompt', function () {
    ($this->fakeStatus)('FAILED', 'PAYER_REJECTED');

    $entry = TabEntry::factory()->for($this->tab)->confirmed()->amount('64.50')->create();
    ($this->snapshot)($entry);

    app(ApplyMomoResult::class)->handle($this->paymentRequest);

    expect($this->paymentRequest->fresh()->status)->toBe(PaymentRequestStatus::Failed)
        ->and($this->paymentRequest->fresh()->failure_reason)->toBe('PAYER_REJECTED')
        ->and($entry->fresh()->status)->toBe(TabEntryStatus::Confirmed)
        ->and($this->tab->outstandingBalance())->toBe('64.50');
});

it('stores a nested momo reason and writes a log for the cloud', function () {
    Event::fake([MessageLogged::class]);

    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
        '*/collection/v1_0/requesttopay/*' => Http::response([
            'status' => 'FAILED',
            'reason' => ['code' => 'NOT_ALLOWED'],
        ]),
    ]);

    app(ApplyMomoResult::class)->handle($this->paymentRequest);

    expect($this->paymentRequest->fresh()->failure_reason)->toBe('NOT_ALLOWED');

    Event::assertDispatched(MessageLogged::class, fn (MessageLogged $log) => $log->message === 'MoMo payment failed'
        && $log->context['reference_id'] === $this->paymentRequest->reference_id
        && $log->context['reason'] === 'NOT_ALLOWED');
});

it('refuses to reopen a payment that already reached a final state', function () {
    Http::fake();

    $settled = PaymentRequest::factory()->for($this->tab)->successful()->create();

    app(ApplyMomoResult::class)->handle($settled);

    Http::assertNothingSent();
});

it('is safe to apply the same successful result twice', function () {
    ($this->fakeStatus)('SUCCESSFUL');

    $entry = TabEntry::factory()->for($this->tab)->confirmed()->amount('64.50')->create();
    ($this->snapshot)($entry);

    app(ApplyMomoResult::class)->handle($this->paymentRequest);
    app(ApplyMomoResult::class)->handle($this->paymentRequest->fresh());

    expect($entry->fresh()->status)->toBe(TabEntryStatus::Settled)
        ->and($this->tab->outstandingBalance())->toBe('0.00');
});
