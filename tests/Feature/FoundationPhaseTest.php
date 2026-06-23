<?php

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

test('phase one pages render for super admin', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();

    $this->actingAs($admin)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Total Users');

    $this->actingAs($admin)->get('/users')->assertOk()->assertSee('Users');
    $this->actingAs($admin)->get('/users/create')->assertOk()->assertSee('Create User');
    $this->actingAs($admin)->get('/roles')->assertOk()->assertSee('Roles & Permissions');
    $this->actingAs($admin)->get('/branches')->assertOk()->assertSee('Branches');
    $this->actingAs($admin)->get('/branches/create')->assertOk()->assertSee('Create Branch');
    $this->actingAs($admin)->get('/settings')->assertOk()->assertSee('System Settings');
});

test('role middleware protects administrative pages', function () {
    $cashier = User::factory()->create(['status' => 'active']);
    $cashier->assignRole('Cashier');

    $this->actingAs($cashier)->get('/users')->assertForbidden();
    $this->actingAs($cashier)->get('/roles')->assertForbidden();
    $this->actingAs($cashier)->get('/settings')->assertForbidden();
    $this->actingAs($cashier)->get('/branches')->assertForbidden();
});

test('manager can manage branches but not users or settings', function () {
    $manager = User::factory()->create(['status' => 'active']);
    $manager->assignRole('Manager');

    $this->actingAs($manager)->get('/branches')->assertOk();
    $this->actingAs($manager)->get('/users')->assertForbidden();
    $this->actingAs($manager)->get('/settings')->assertForbidden();
});

test('inactive users cannot login', function () {
    $user = User::factory()->create([
        'email' => 'inactive@buildmart.test',
        'status' => 'inactive',
    ]);

    Volt::test('pages.auth.login')
        ->set('form.email', $user->email)
        ->set('form.password', 'password')
        ->call('login')
        ->assertHasErrors(['form.email']);

    $this->assertGuest();
});

test('default branch and super admin are seeded', function () {
    expect(Branch::where('code', 'MAIN')->exists())->toBeTrue();
    expect(User::where('email', 'admin@buildmart.test')->first()->hasRole('Super Admin'))->toBeTrue();
});
