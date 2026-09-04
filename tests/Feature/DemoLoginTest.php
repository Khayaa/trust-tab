<?php

use App\Models\Tab;
use App\Models\TabEntry;
use App\Models\User;

it('hides the demo shortcut when the flag is off, leaving the real form', function () {
    config()->set('trusttab.demo_auth', false);

    $person = User::factory()->create(['name' => 'Nandi Dlamini']);

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Sign in')
        ->assertDontSee('Nandi Dlamini');

    /*
     * The route is gated as well as the button, so knowing the URL is not
     * enough to hand yourself a session.
     */
    $this->post(route('demo-login.store', $person))->assertNotFound();

    $this->assertGuest();
});

it('signs a seeded person in when the flag is on', function () {
    config()->set('trusttab.demo_auth', true);

    $user = User::factory()->create();

    $this->post(route('demo-login.store', $user))
        ->assertRedirect(route('tabs'));

    $this->assertAuthenticatedAs($user);
});

it('lists the seeded people to choose from', function () {
    config()->set('trusttab.demo_auth', true);

    User::factory()->merchant('Mama Nandi Spaza')->create(['name' => 'Nandi Dlamini']);
    User::factory()->create(['name' => 'Sipho Khumalo']);

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Nandi Dlamini')
        ->assertSee('Mama Nandi Spaza')
        ->assertSee('Sipho Khumalo');
});

it('sends guests to the login page', function () {
    $this->get(route('tabs'))->assertRedirect(route('login'));
});

it('shows each person only their own tabs', function () {
    $merchant = User::factory()->merchant('Mama Nandi Spaza')->create();
    $customer = User::factory()->create(['name' => 'Sipho Khumalo']);
    Tab::factory()->between($merchant, $customer)->create();

    $stranger = User::factory()->create();
    Tab::factory()->create(['customer_id' => $stranger->id]);

    $this->actingAs($merchant)
        ->get(route('tabs'))
        ->assertOk()
        ->assertSee('Sipho Khumalo');

    $this->actingAs($stranger)
        ->get(route('tabs'))
        ->assertOk()
        ->assertDontSee('Sipho Khumalo');
});

it('signs a person out', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('tells the merchant when a tab is stuck on a dispute', function () {
    $merchant = User::factory()->merchant('Mama Nandi Spaza')->create();
    $customer = User::factory()->create(['name' => 'Sipho Khumalo']);
    $tab = Tab::factory()->between($merchant, $customer)->create();

    TabEntry::factory()->for($tab)->confirmed()->amount('90.00')->create();
    TabEntry::factory()->for($tab)->create();
    TabEntry::factory()->for($tab)->disputed()->create();

    $this->actingAs($merchant)
        ->get(route('tabs'))
        ->assertOk()
        ->assertSee('1 disputed, needs you')
        ->assertDontSee('All items confirmed');
});

it('nudges the customer about items waiting on them', function () {
    $merchant = User::factory()->merchant()->create();
    $customer = User::factory()->create();
    $tab = Tab::factory()->between($merchant, $customer)->create();

    TabEntry::factory()->for($tab)->create();

    $this->actingAs($customer)
        ->get(route('tabs'))
        ->assertOk()
        ->assertSee('1 awaiting your confirmation');
});
