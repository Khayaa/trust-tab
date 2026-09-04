<?php

use App\Actions\RequestTabSettlement;
use App\Enums\PaymentRequestStatus;
use App\Enums\TabEntryStatus;
use App\Exceptions\MomoRequestFailed;
use App\Models\PaymentRequest;
use App\Models\Tab;
use App\Models\TabEntry;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

beforeEach(function () {
    $this->merchant = User::factory()->merchant()->create();
    $this->customer = User::factory()->create(['msisdn' => '46733123453']);
    $this->tab = Tab::factory()->between($this->merchant, $this->customer)->create();

    $this->fakeMomo = function (mixed $requestToPay) {
        Http::fake([
            '*/collection/token/' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
            '*/collection/v1_0/requesttopay' => $requestToPay,
        ]);
    };
});

it('asks the customer to pay everything confirmed on the tab', function () {
    ($this->fakeMomo)(Http::response(status: 202));

    TabEntry::factory()->for($this->tab)->confirmed()->amount('45.50')->create();
    TabEntry::factory()->for($this->tab)->confirmed()->amount('19.00')->create();

    $paymentRequest = app(RequestTabSettlement::class)->handle($this->tab);

    expect($paymentRequest->amount)->toBe('64.50')
        ->and($paymentRequest->status)->toBe(PaymentRequestStatus::Pending)
        ->and($paymentRequest->requested_at)->not->toBeNull()
        ->and($paymentRequest->payer_msisdn)->toBe('46733123453')
        ->and($paymentRequest->snapshotEntries)->toHaveCount(2);
});

it('leaves unconfirmed and disputed entries out of the payment', function () {
    ($this->fakeMomo)(Http::response(status: 202));

    TabEntry::factory()->for($this->tab)->confirmed()->amount('45.50')->create();
    TabEntry::factory()->for($this->tab)->amount('100.00')->create();
    TabEntry::factory()->for($this->tab)->disputed()->amount('80.00')->create();

    $paymentRequest = app(RequestTabSettlement::class)->handle($this->tab);

    expect($paymentRequest->amount)->toBe('45.50')
        ->and($paymentRequest->snapshotEntries)->toHaveCount(1);
});

it('sends a version 4 uuid as the reference, because momo rejects anything else', function () {
    ($this->fakeMomo)(Http::response(status: 202));

    TabEntry::factory()->for($this->tab)->confirmed()->amount('45.50')->create();

    $paymentRequest = app(RequestTabSettlement::class)->handle($this->tab);

    expect(Str::isUuid($paymentRequest->reference_id))->toBeTrue()
        ->and(Uuid::fromString($paymentRequest->reference_id)->getFields()->getVersion())->toBe(4);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://sandbox.momodeveloper.mtn.com/collection/v1_0/requesttopay'
        && $request->header('X-Reference-Id')[0] === $paymentRequest->reference_id
        && $request->header('X-Target-Environment')[0] === 'sandbox');
});

it('settles in the currency momo accepts, not the one on screen', function () {
    ($this->fakeMomo)(Http::response(status: 202));

    TabEntry::factory()->for($this->tab)->confirmed()->amount('45.50')->create();

    $paymentRequest = app(RequestTabSettlement::class)->handle($this->tab);

    expect($paymentRequest->currency)->toBe(config('services.momo.currency'))
        ->and($paymentRequest->currency)->not->toBe(config('trusttab.money.currency'));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/requesttopay')
        && $request['currency'] === config('services.momo.currency'));
});

it('treats a duplicate reference as already in flight rather than retrying the debit', function () {
    ($this->fakeMomo)(Http::response(['code' => 'RESOURCE_ALREADY_EXIST'], 409));

    TabEntry::factory()->for($this->tab)->confirmed()->amount('45.50')->create();

    $paymentRequest = app(RequestTabSettlement::class)->handle($this->tab);

    expect($paymentRequest->status)->toBe(PaymentRequestStatus::Pending);
});

it('sends a callback url whose host momo can match', function () {
    config()->set('services.momo.callback_host', 'trusttab.laravel.cloud');
    ($this->fakeMomo)(Http::response(status: 202));

    TabEntry::factory()->for($this->tab)->confirmed()->amount('45.50')->create();

    app(RequestTabSettlement::class)->handle($this->tab);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/requesttopay')
        && $request->hasHeader('X-Callback-Url', 'https://trusttab.laravel.cloud/momo/callback'));
});

it('retries the same debit without a callback when momo rejects the host', function () {
    config()->set('services.momo.callback_host', 'trusttab.laravel.cloud');
    Event::fake([MessageLogged::class]);

    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
        '*/collection/v1_0/requesttopay' => Http::sequence()
            ->push(['code' => 'INVALID_CALLBACK_URL_HOST'], 400)
            ->push(status: 202),
    ]);

    TabEntry::factory()->for($this->tab)->confirmed()->amount('45.50')->create();

    $paymentRequest = app(RequestTabSettlement::class)->handle($this->tab);

    expect($paymentRequest->status)->toBe(PaymentRequestStatus::Pending);
    expect($this->tab->outstandingBalance())->toBe('45.50');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/requesttopay')
        && $request->hasHeader('X-Callback-Url')
        && $request->header('X-Reference-Id')[0] === $paymentRequest->reference_id);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/requesttopay')
        && ! $request->hasHeader('X-Callback-Url')
        && $request->header('X-Reference-Id')[0] === $paymentRequest->reference_id);

    Http::assertSentCount(3);

    Event::assertDispatched(MessageLogged::class, fn (MessageLogged $log) => $log->message === 'MoMo rejected the callback host; retrying without a callback'
        && $log->context['reference_id'] === $paymentRequest->reference_id);
});

it('records the rejection and leaves the tab untouched when momo refuses', function () {
    ($this->fakeMomo)(Http::response(['code' => 'PAYER_NOT_FOUND'], 400));

    $entry = TabEntry::factory()->for($this->tab)->confirmed()->amount('45.50')->create();

    expect(fn () => app(RequestTabSettlement::class)->handle($this->tab))
        ->toThrow(MomoRequestFailed::class);

    $paymentRequest = $this->tab->paymentRequests()->sole();

    expect($paymentRequest->status)->toBe(PaymentRequestStatus::Failed)
        ->and($paymentRequest->failure_reason)->toBe('PAYER_NOT_FOUND')
        ->and($entry->fresh()->status)->toBe(TabEntryStatus::Confirmed)
        ->and($this->tab->outstandingBalance())->toBe('45.50');
});

it('refuses to raise a payment for a tab with nothing outstanding', function () {
    Http::fake();

    TabEntry::factory()->for($this->tab)->amount('45.50')->create();

    expect(fn () => app(RequestTabSettlement::class)->handle($this->tab))
        ->toThrow(RuntimeException::class);

    expect($this->tab->paymentRequests()->count())->toBe(0);
});

it('returns the in-flight payment instead of raising a second debit', function () {
    ($this->fakeMomo)(Http::response(status: 202));

    TabEntry::factory()->for($this->tab)->confirmed()->amount('45.50')->create();

    $first = app(RequestTabSettlement::class)->handle($this->tab);
    $second = app(RequestTabSettlement::class)->handle($this->tab);

    expect($second->is($first))->toBeTrue()
        ->and($this->tab->paymentRequests()->count())->toBe(1);

    Http::assertSentCount(2);
});

it('sends a payment that was created but never reached momo', function () {
    ($this->fakeMomo)(Http::response(status: 202));

    TabEntry::factory()->for($this->tab)->confirmed()->amount('45.50')->create();

    $orphaned = PaymentRequest::factory()->for($this->tab)->create([
        'amount' => '45.50',
        'payer_msisdn' => $this->customer->msisdn,
        'external_reference' => 'tab-'.$this->tab->id.'-orphaned',
    ]);

    $paymentRequest = app(RequestTabSettlement::class)->handle($this->tab);

    expect($paymentRequest->is($orphaned))->toBeTrue()
        ->and($paymentRequest->status)->toBe(PaymentRequestStatus::Pending)
        ->and($this->tab->paymentRequests()->count())->toBe(1);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/requesttopay'));
});

it('reuses one access token across calls', function () {
    ($this->fakeMomo)(Http::response(status: 202));

    TabEntry::factory()->for($this->tab)->confirmed()->amount('10.00')->create();
    app(RequestTabSettlement::class)->handle($this->tab);

    $otherCustomer = User::factory()->create(['msisdn' => '46733123454']);
    $otherTab = Tab::factory()->between($this->merchant, $otherCustomer)->create();
    TabEntry::factory()->for($otherTab)->confirmed()->amount('10.00')->create();
    app(RequestTabSettlement::class)->handle($otherTab);

    Http::assertSentCount(3);
});
