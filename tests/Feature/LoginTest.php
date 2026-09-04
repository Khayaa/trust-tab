<?php

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('trusttab.demo_auth', false);

    $this->person = User::factory()->create([
        'email' => 'nandi@trusttab.test',
        'password' => Hash::make('correct-horse'),
    ]);
});

it('signs a person in with their password', function () {
    Livewire::test('pages::login')
        ->set('email', 'nandi@trusttab.test')
        ->set('password', 'correct-horse')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('tabs'));

    $this->assertAuthenticatedAs($this->person);
});

it('turns away a wrong password', function () {
    Livewire::test('pages::login')
        ->set('email', 'nandi@trusttab.test')
        ->set('password', 'guessing')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});

it('will not say whether an email is registered here', function () {
    /*
     * A tab is a record of what someone owes. The form must not double as a way
     * to find out who has an account, so both failures read identically.
     */
    $unknown = Livewire::test('pages::login')
        ->set('email', 'stranger@trusttab.test')
        ->set('password', 'guessing')
        ->call('login')
        ->errors()
        ->get('email');

    $wrongPassword = Livewire::test('pages::login')
        ->set('email', 'nandi@trusttab.test')
        ->set('password', 'guessing')
        ->call('login')
        ->errors()
        ->get('email');

    expect($unknown)->toBe($wrongPassword);
});

it('stops guessing after five tries', function () {
    Event::fake([Lockout::class]);

    $component = Livewire::test('pages::login')
        ->set('email', 'nandi@trusttab.test')
        ->set('password', 'guessing');

    foreach (range(1, 5) as $ignored) {
        $component->call('login')->assertHasErrors('email');
    }

    $component->call('login');

    expect($component->errors()->get('email')[0])->toContain('Too many attempts');

    Event::assertDispatched(Lockout::class);
    $this->assertGuest();
});

it('lets the right password through after earlier failures', function () {
    $component = Livewire::test('pages::login')
        ->set('email', 'nandi@trusttab.test')
        ->set('password', 'guessing');

    $component->call('login')->assertHasErrors('email');

    $component->set('password', 'correct-horse')
        ->call('login')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($this->person);
});

it('asks for both fields', function () {
    Livewire::test('pages::login')
        ->call('login')
        ->assertHasErrors(['email' => 'required', 'password' => 'required']);
});

it('rejects something that is not an email', function () {
    Livewire::test('pages::login')
        ->set('email', 'not-an-email')
        ->set('password', 'correct-horse')
        ->call('login')
        ->assertHasErrors(['email' => 'email']);

    $this->assertGuest();
});

it('keeps the person signed in when they ask it to', function () {
    /*
     * Laravel only writes a remember token when one is missing and the box was
     * ticked, so starting from empty is what makes this prove the checkbox
     * reached Auth::attempt rather than being quietly ignored.
     */
    $this->person->forceFill(['remember_token' => null])->save();

    Livewire::test('pages::login')
        ->set('email', 'nandi@trusttab.test')
        ->set('password', 'correct-horse')
        ->set('remember', true)
        ->call('login')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($this->person);

    expect($this->person->fresh()->remember_token)->not->toBeNull();
});

it('does not keep the person signed in when they do not ask', function () {
    $this->person->forceFill(['remember_token' => null])->save();

    Livewire::test('pages::login')
        ->set('email', 'nandi@trusttab.test')
        ->set('password', 'correct-horse')
        ->call('login')
        ->assertHasNoErrors();

    expect($this->person->fresh()->remember_token)->toBeNull();
});

it('gives the person a fresh session, so a stolen one is worthless', function () {
    $before = session()->getId();

    Livewire::test('pages::login')
        ->set('email', 'nandi@trusttab.test')
        ->set('password', 'correct-horse')
        ->call('login');

    expect(session()->getId())->not->toBe($before);
});
