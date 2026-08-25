<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockLocation;
use App\Models\User;
use App\Support\AuthorizationScope;
use Database\Seeders\DatabaseSeeder;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

function authorizationCashier(Branch $branch, string $email): User
{
    $user = User::factory()->create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'email' => $email,
        'status' => 'active',
    ]);
    $user->assignRole('Cashier');

    return $user;
}

function authorizationSaleFor(User $user, string $number): Sale
{
    return Sale::withoutGlobalScopes()->create([
        'sale_number' => $number,
        'company_id' => $user->company_id,
        'branch_id' => $user->branch_id,
        'stock_location_id' => StockLocation::withoutGlobalScopes()->where('branch_id', $user->branch_id)->value('id'),
        'sold_by' => $user->id,
        'created_by' => $user->id,
        'sale_date' => today(),
        'sale_type' => 'retail',
        'subtotal' => 1000,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 1000,
        'paid_amount' => 1000,
        'balance_amount' => 0,
        'change_amount' => 0,
        'payment_status' => 'paid',
        'status' => 'completed',
    ]);
}

test('cashier sales scope includes own sales and excludes another cashiers sales', function () {
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $cashier = authorizationCashier($branch, 'scope-one@example.test');
    $other = authorizationCashier($branch, 'scope-two@example.test');
    $mine = authorizationSaleFor($cashier, 'AUTH-MINE-001');
    $theirs = authorizationSaleFor($other, 'AUTH-THEIRS-001');

    $visibleIds = AuthorizationScope::sales(Sale::withoutGlobalScopes(), $cashier)->pluck('id');

    expect($visibleIds)->toContain($mine->id)
        ->not->toContain($theirs->id);

    $this->actingAs($cashier)->get(route('sales.show', $theirs))->assertForbidden();
});

test('cashier cannot open profit report or invoke generic report export directly', function () {
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $cashier = authorizationCashier($branch, 'restricted-reports@example.test');

    $this->actingAs($cashier)->get(route('reports.profit-loss'))->assertForbidden();
    $this->actingAs($cashier)
        ->get(route('exports.download', ['export' => 'tables.sales', 'format' => 'excel']))
        ->assertForbidden();
});

test('cashier product list does not render buying price', function () {
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $cashier = authorizationCashier($branch, 'hidden-cost@example.test');

    $this->actingAs($cashier)
        ->get(route('products.index'))
        ->assertOk()
        ->assertDontSee('Buying Price');
});

test('assigned location scope returns only locations the user may view', function () {
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $cashier = authorizationCashier($branch, 'location-scope@example.test');
    $locations = StockLocation::query()->where('branch_id', $branch->id)->take(2)->get();

    expect($locations)->toHaveCount(2);

    $cashier->stockLocations()->sync([
        $locations[0]->id => [
            'company_id' => $branch->company_id,
            'branch_id' => $branch->id,
            'can_view' => true,
            'can_sell' => true,
            'can_transfer' => false,
            'can_receive' => false,
            'can_adjust' => false,
            'is_default' => true,
        ],
        $locations[1]->id => [
            'company_id' => $branch->company_id,
            'branch_id' => $branch->id,
            'can_view' => false,
            'can_sell' => false,
            'can_transfer' => false,
            'can_receive' => false,
            'can_adjust' => false,
            'is_default' => false,
        ],
    ]);

    expect(AuthorizationScope::stockLocationIds($cashier)->all())
        ->toBe([$locations[0]->id]);
});

test('cashier dashboard renders without profit and stock value cards', function () {
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $cashier = authorizationCashier($branch, 'cashier-dashboard@example.test');

    $this->actingAs($cashier)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('reports.profit-loss'))
        ->assertDontSee(route('reports.stock-valuation'));
});

test('branch scoped manager cannot see sales from another branch', function () {
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $otherBranch = Branch::withoutGlobalScopes()->create([
        'company_id' => $branch->company_id,
        'name' => 'Authorization Branch',
        'code' => 'AUTH-BR',
        'status' => 'active',
    ]);
    $manager = User::factory()->create(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'status' => 'active']);
    $manager->assignRole('Manager');
    $otherUser = User::factory()->create(['company_id' => $branch->company_id, 'branch_id' => $otherBranch->id, 'status' => 'active']);
    $otherUser->assignRole('Cashier');
    $mine = authorizationSaleFor($manager, 'AUTH-MANAGER-BRANCH');
    $other = authorizationSaleFor($otherUser, 'AUTH-OTHER-BRANCH');

    $visibleIds = AuthorizationScope::sales(Sale::withoutGlobalScopes(), $manager)->pluck('id');

    expect($visibleIds)->toContain($mine->id)->not->toContain($other->id);
});

test('company scope never crosses the company boundary', function () {
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $otherCompany = Company::create([
        'company_name' => 'Other Company',
        'business_type' => 'Hardware',
        'phone' => '255700000001',
        'whatsapp_number' => '255700000001',
        'currency' => 'TZS',
        'timezone' => 'Africa/Dar_es_Salaam',
    ]);
    $otherBranch = Branch::withoutGlobalScopes()->create([
        'company_id' => $otherCompany->id,
        'name' => 'Other Company Branch',
        'code' => 'OTHER-CO',
        'status' => 'active',
    ]);
    $admin = User::factory()->create(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'status' => 'active']);
    $admin->assignRole('Admin');
    $otherAdmin = User::factory()->create(['company_id' => $otherCompany->id, 'branch_id' => $otherBranch->id, 'status' => 'active']);
    $otherAdmin->assignRole('Admin');
    $ownCompanySale = authorizationSaleFor($admin, 'AUTH-COMPANY-OWN');
    $otherCompanySale = authorizationSaleFor($otherAdmin, 'AUTH-COMPANY-OTHER');

    $visibleIds = AuthorizationScope::sales(Sale::withoutGlobalScopes(), $admin)->pluck('id');

    expect($visibleIds)->toContain($ownCompanySale->id)->not->toContain($otherCompanySale->id);
});

test('restricted product editor cannot overwrite buying or selling prices through Livewire state', function () {
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $role = Role::create([
        'name' => 'Restricted Product Editor',
        'guard_name' => 'web',
        'sales_scope' => 'own',
        'stock_scope' => 'assigned_locations',
        'report_scope' => 'own',
    ]);
    $role->givePermissionTo(['products.view', 'products.edit']);
    $editor = User::factory()->create(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'status' => 'active']);
    $editor->assignRole($role);
    $product = Product::where('sku', 'BM-NON-Y12')->firstOrFail();
    $originalBuying = $product->buying_price;
    $originalSelling = $product->selling_price;

    $this->actingAs($editor);

    Volt::test('products.edit', ['product' => $product])
        ->set('buying_price', '1')
        ->set('selling_price', '2')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->refresh()->buying_price)->toBe($originalBuying)
        ->and($product->selling_price)->toBe($originalSelling);
});

test('product export omits buying prices when the viewer lacks cost permission', function () {
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $cashier = authorizationCashier($branch, 'safe-product-export@example.test');
    $cashier->givePermissionTo('reports.export');

    $this->actingAs($cashier)
        ->get(route('exports.download', ['export' => 'tables.products', 'format' => 'excel']))
        ->assertOk()
        ->assertDontSee('Buying Price');
});

test('role administrator can persist feature permissions and data scopes together', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $role = Role::create([
        'name' => 'Scoped Operator',
        'guard_name' => 'web',
        'sales_scope' => 'own',
        'stock_scope' => 'assigned_locations',
        'report_scope' => 'own',
    ]);

    $this->actingAs($admin);

    Volt::test('roles.index')
        ->call('editRole', $role->id)
        ->set('permissions', ['sales.view', 'stock.view'])
        ->set('sales_scope', 'branch')
        ->set('stock_scope', 'branch')
        ->set('report_scope', 'branch')
        ->call('save')
        ->assertHasNoErrors();

    $role->refresh();
    expect($role->sales_scope)->toBe('branch')
        ->and($role->stock_scope)->toBe('branch')
        ->and($role->report_scope)->toBe('branch')
        ->and($role->hasAllPermissions(['sales.view', 'stock.view']))->toBeTrue();
});
