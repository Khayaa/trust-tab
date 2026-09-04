<?php

use App\Enums\PaymentRequestStatus;
use App\Enums\TabEntryStatus;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestEntry;
use App\Models\Tab;
use App\Models\TabEntry;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tab = Tab::factory()
        ->between(User::factory()->merchant()->create(), User::factory()->create())
        ->create();
});

it('finishes a payment whose callback never arrived', function () {
    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
        '*/collection/v1_0/requesttopay/*' => Http::response(['status' => 'SUCCESSFUL']),
    ]);

    $entry = TabEntry::factory()->for($this->tab)->confirmed()->amount('45.50')->create();

    $paymentRequest = PaymentRequest::factory()->for($this->tab)->pending()->create([
        'amount' => '45.50',
        'created_at' => now()->subMinutes(5),
    ]);

    PaymentRequestEntry::factory()->create([
        'payment_request_id' => $paymentRequest->id,
        'tab_entry_id' => $entry->id,
        'amount' => $entry->amount,
    ]);

    $this->artisan('payments:reconcile-pending')->assertSuccessful();

    expect($paymentRequest->fresh()->status)->toBe(PaymentRequestStatus::Successful)
        ->and($entry->fresh()->status)->toBe(TabEntryStatus::Settled);
});

it('leaves a payment raised moments ago alone, so the customer still has time to approve', function () {
    Http::fake();

    PaymentRequest::factory()->for($this->tab)->pending()->create();

    $this->artisan('payments:reconcile-pending')->assertSuccessful();

    Http::assertNothingSent();
});

it('keeps going when momo is unreachable for one payment', function () {
    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
        '*/collection/v1_0/requesttopay/*' => Http::response(status: 500),
    ]);

    PaymentRequest::factory()->for($this->tab)->pending()->create([
        'created_at' => now()->subMinutes(5),
    ]);

    $this->artisan('payments:reconcile-pending')->assertSuccessful();
});

it('closes a payment that never reached momo instead of asking about it forever', function () {
    Http::fake();

    $entry = TabEntry::factory()->for($this->tab)->confirmed()->amount('45.50')->create();

    /*
     * The row committed and then something failed before the call went out, so
     * MoMo has never heard of this reference and never will.
     */
    $orphan = PaymentRequest::factory()->for($this->tab)->create([
        'amount' => '45.50',
        'requested_at' => null,
        'created_at' => now()->subMinutes(5),
    ]);

    $this->artisan('payments:reconcile-pending')->assertSuccessful();

    expect($orphan->fresh()->status)->toBe(PaymentRequestStatus::Failed)
        ->and($orphan->fresh()->failure_reason)->toBe('NEVER_SENT')
        ->and($entry->fresh()->status)->toBe(TabEntryStatus::Confirmed)
        ->and($this->tab->outstandingBalance())->toBe('45.50');

    Http::assertNothingSent();
});

it('leaves a payment that did reach momo to be asked about normally', function () {
    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
        '*/collection/v1_0/requesttopay/*' => Http::response(['status' => 'PENDING']),
    ]);

    $sent = PaymentRequest::factory()->for($this->tab)->pending()->create([
        'requested_at' => now()->subMinutes(4),
        'created_at' => now()->subMinutes(5),
    ]);

    $this->artisan('payments:reconcile-pending')->assertSuccessful();

    expect($sent->fresh()->status)->toBe(PaymentRequestStatus::Pending);
});

it('ignores payments that already reached a final state', function () {
    Http::fake();

    PaymentRequest::factory()->for($this->tab)->successful()->create([
        'created_at' => now()->subMinutes(5),
    ]);
    PaymentRequest::factory()->for($this->tab)->failed()->create([
        'created_at' => now()->subMinutes(5),
    ]);

    $this->artisan('payments:reconcile-pending')->assertSuccessful();

    Http::assertNothingSent();
});
