<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Services\PdfExportService;
use Database\Seeders\DatabaseSeeder;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

test('phase one pages render for super admin', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();

    $this->actingAs($admin)
        ->get('/dashboard')
        ->assertOk();

    $this->actingAs($admin)->get('/users')->assertOk();
    $this->actingAs($admin)->get('/users/create')->assertOk();
    $this->actingAs($admin)->get('/roles')->assertOk();
    $this->actingAs($admin)->get('/branches')->assertOk();
    $this->actingAs($admin)->get('/branches/create')->assertOk();
    $this->actingAs($admin)->get('/settings')->assertOk();
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

test('super admin can download pdf and excel exports', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();

    $this->actingAs($admin)
        ->get(route('exports.download', ['export' => 'tables.users', 'format' => 'excel']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.ms-excel; charset=UTF-8');

    $this->actingAs($admin)
        ->get(route('exports.download', ['export' => 'tables.users', 'format' => 'pdf']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('products pdf uses serial numbers cyan styling and uppercase product names', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $firstProduct = Product::query()->orderBy('name')->firstOrFail();

    $pdf = Mockery::mock(PdfExportService::class);
    $pdf->shouldReceive('generatePdf')
        ->once()
        ->with('Products', Mockery::on(function (array $payload) use ($firstProduct): bool {
            expect($payload['headers'][0])->toBe('S/N')
                ->and($payload['rows'][0][0])->toBe(1)
                ->and($payload['rows'][0][1])->toBe(mb_strtoupper($firstProduct->displayName()))
                ->and($payload['table_theme'])->toBe('cyan');

            return true;
        }))
        ->andReturn('%PDF');

    $this->app->instance(PdfExportService::class, $pdf);

    $this->actingAs($admin)
        ->get(route('exports.download', ['export' => 'tables.products', 'format' => 'pdf']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});
