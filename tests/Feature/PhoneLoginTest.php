<?php

use App\Actions\OpenTabWithCustomer;
use App\Actions\SendLoginOtp;
use App\Auth\LoginOtp;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

function issueFixedLoginOtp(string $code = '123456'): void
{
    app()->instance(LoginOtp::class, new class($code) extends LoginOtp
    {
        public function __construct(private string $fixed) {}

        public function issue(): string
        {
            return $this->fixed;
        }
    });
}

beforeEach(function () {
    config()->set('trusttab.demo_auth', false);
    config()->set('trusttab.otp.reveal', false);

    $this->customer = User::factory()->create([
        'name' => 'Sipho Khumalo',
        'msisdn' => '27723041887',
        'email' => null,
        'password' => null,
    ]);
});

it('signs in a customer who only has a momo number', function () {
    issueFixedLoginOtp();

    Livewire::test('pages::login')
        ->set('msisdn', '072 304 1887')
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('codeSent', true)
        ->set('code', '123456')
        ->call('verifyCode')
        ->assertHasNoErrors()
        ->assertRedirect(route('tabs'));

    $this->assertAuthenticatedAs($this->customer);
});

it('lets a merchant-created customer in with the number the shop typed', function () {
    issueFixedLoginOtp();

    $merchant = User::factory()->merchant()->create();
    $tab = app(OpenTabWithCustomer::class)->handle($merchant, 'Thandi Nkosi', '083 551 0194');

    Livewire::test('pages::login')
        ->set('msisdn', '083 551 0194')
        ->call('sendCode')
        ->set('code', '123456')
        ->call('verifyCode')
        ->assertRedirect(route('tabs'));

    $this->assertAuthenticatedAs($tab->customer);

    $this->actingAs($tab->customer)
        ->get(route('tabs'))
        ->assertOk()
        ->assertSee($merchant->merchantProfile->business_name);
});

it('stores a hash of the code, never the code itself', function () {
    issueFixedLoginOtp();

    app(SendLoginOtp::class)->handle('27723041887');

    $stored = Cache::get(app(LoginOtp::class)->hashKey('27723041887'));

    expect($stored)->not->toBe('123456')
        ->and(Hash::check('123456', $stored))->toBeTrue();
});

it('will not say whether a number has a tab', function () {
    issueFixedLoginOtp();

    $unknown = Livewire::test('pages::login')
        ->set('msisdn', '079 000 0001')
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('codeSent', true)
        ->set('code', '123456')
        ->call('verifyCode')
        ->errors()
        ->get('code');

    $wrong = Livewire::test('pages::login')
        ->set('msisdn', '072 304 1887')
        ->call('sendCode')
        ->set('code', '000000')
        ->call('verifyCode')
        ->errors()
        ->get('code');

    expect($unknown)->toBe($wrong)
        ->and(User::query()->where('msisdn', '27790000001')->exists())->toBeFalse();

    $this->assertGuest();
});

it('stops guessing codes after five tries', function () {
    issueFixedLoginOtp();
    Event::fake([Lockout::class]);

    $component = Livewire::test('pages::login')
        ->set('msisdn', '072 304 1887')
        ->call('sendCode')
        ->set('code', '000000');

    foreach (range(1, 5) as $ignored) {
        $component->call('verifyCode')->assertHasErrors('code');
    }

    $component->call('verifyCode');

    expect($component->errors()->get('code')[0])->toContain('Too many attempts');

    Event::assertDispatched(Lockout::class);
    $this->assertGuest();
});

it('stops sending codes after three tries', function () {
    issueFixedLoginOtp();
    Event::fake([Lockout::class]);

    $component = Livewire::test('pages::login')->set('msisdn', '072 304 1887');

    foreach (range(1, 3) as $ignored) {
        $component->call('sendCode')->assertHasNoErrors();
        $component->set('codeSent', false);
    }

    $component->call('sendCode');

    expect($component->errors()->get('msisdn')[0])->toContain('Too many codes');

    Event::assertDispatched(Lockout::class);
});

it('throws the code away after a successful sign in', function () {
    issueFixedLoginOtp();

    Livewire::test('pages::login')
        ->set('msisdn', '072 304 1887')
        ->call('sendCode')
        ->set('code', '123456')
        ->call('verifyCode');

    Auth::logout();

    Livewire::test('pages::login')
        ->set('msisdn', '072 304 1887')
        ->set('codeSent', true)
        ->set('code', '123456')
        ->call('verifyCode')
        ->assertHasErrors('code');

    $this->assertGuest();
});

it('does not print the code on the page unless reveal is on', function () {
    issueFixedLoginOtp();

    Livewire::test('pages::login')
        ->set('msisdn', '072 304 1887')
        ->call('sendCode')
        ->assertDontSee('123456')
        ->assertDontSee('Demo code');

    config()->set('trusttab.otp.reveal', true);

    Livewire::test('pages::login')
        ->set('msisdn', '072 304 1887')
        ->call('sendCode')
        ->assertSee('123456')
        ->assertSee('Demo code');
});

it('does not show a typeable demo code for a number that has no tab', function () {
    config()->set('trusttab.otp.reveal', true);
    issueFixedLoginOtp('654321');

    Livewire::test('pages::login')
        ->set('msisdn', '079 000 0001')
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('codeSent', true)
        ->assertDontSee('654321')
        ->assertDontSee('Demo code')
        ->set('code', '654321')
        ->call('verifyCode')
        ->assertHasErrors('code');

    $this->assertGuest();
});

it('accepts the revealed demo code for a number the shop has saved', function () {
    config()->set('trusttab.otp.reveal', true);
    issueFixedLoginOtp('640178');

    Livewire::test('pages::login')
        ->set('msisdn', '072 304 1887')
        ->call('sendCode')
        ->assertSee('640178')
        ->set('code', '640178')
        ->call('verifyCode')
        ->assertHasNoErrors()
        ->assertRedirect(route('tabs'));

    $this->assertAuthenticatedAs($this->customer);
});

it('gives the person a fresh session after the code is accepted', function () {
    issueFixedLoginOtp();

    $before = session()->getId();

    Livewire::test('pages::login')
        ->set('msisdn', '072 304 1887')
        ->call('sendCode')
        ->set('code', '123456')
        ->call('verifyCode');

    expect(session()->getId())->not->toBe($before);
});
