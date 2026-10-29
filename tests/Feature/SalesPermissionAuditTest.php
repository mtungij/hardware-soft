<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\CompanyRoleService;
use App\Services\InventoryService;
use App\Support\PosSaleDate;
use App\Support\RolePermissionCatalog;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->company = Company::create(['company_name' => 'Permission audit A', 'business_type' => 'hardware', 'phone' => '255700000001', 'whatsapp_number' => '255700000001']);
    $this->foreignCompany = Company::create(['company_name' => 'Permission audit B', 'business_type' => 'hardware', 'phone' => '255700000002', 'whatsapp_number' => '255700000002']);
    $this->branch = Branch::create(['company_id' => $this->company->id, 'name' => 'Main', 'code' => 'MAIN']);
    $this->otherBranch = Branch::create(['company_id' => $this->company->id, 'name' => 'Other', 'code' => 'OTHER']);
    Setting::create(['company_id' => $this->company->id, 'company_name' => 'Audit A', 'inventory_mode' => 'single_location', 'enable_warehouse' => false]);
    foreach (['roles.manage', 'sales.view', 'sales.create', 'sales.cancel', 'sales.discount', 'sales.edit', 'sales.refund', 'sales.backdate', 'edit sales', 'create credit sales', 'create unassigned credit sales', 'receive sale payments', 'company-settings.update'] as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $this->role = Role::create(['company_id' => $this->company->id, 'name' => 'Super Admin', 'guard_name' => 'web']);
    $this->role->syncPermissions(['roles.manage', 'sales.view', 'sales.create', 'sales.cancel']);
    $this->admin = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $this->admin->assignRole($this->role);
    $this->customer = Customer::create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'Credit customer', 'phone' => '255700001111', 'customer_type' => 'credit', 'balance_amount' => 60, 'status' => 'active']);
    $this->location = StockLocation::create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'code' => 'AUDIT', 'name' => 'Counter', 'type' => 'dispensing', 'status' => 'active', 'is_active' => true, 'can_sell' => true, 'is_sellable' => true]);
    $unit = Unit::create(['company_id' => $this->company->id, 'name' => 'Audit piece', 'short_name' => 'aud', 'status' => 'active']);
    $category = Category::create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'Audit category', 'code' => 'AUDIT']);
    $this->product = Product::create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Audit product', 'sku' => 'AUDIT', 'buying_price' => 10, 'selling_price' => 50, 'status' => 'active']);
    $this->sale = Sale::create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'stock_location_id' => $this->location->id, 'customer_id' => $this->customer->id, 'sale_number' => 'AUDIT-SALE', 'sale_date' => today(), 'subtotal' => 100, 'total_amount' => 100, 'paid_amount' => 40, 'balance_amount' => 60, 'payment_status' => 'partial', 'status' => 'completed', 'created_by' => $this->admin->id, 'sold_by' => $this->admin->id]);
    $this->sale->items()->create(['company_id' => $this->company->id, 'product_id' => $this->product->id, 'stock_location_id' => $this->location->id, 'quantity' => 2, 'base_quantity' => 4, 'unit_price' => 50, 'unit_cost' => 20, 'conversion_factor_to_base' => 2, 'line_total' => 100]);
    $this->sale->payments()->create(['company_id' => $this->company->id, 'payment_method' => 'cash', 'amount' => 40, 'received_by' => $this->admin->id, 'payment_date' => today()]);
    $this->actingAs($this->admin)->withSession(['staff_locale' => 'en']);
});

test('super admin catalog and selections show actual grants instead of a global authorization bypass', function () {
    $applicable = RolePermissionCatalog::groups()->flatten()->pluck('name')->all();
    $component = Volt::test('roles.index')->call('editRole', $this->role->id);
    foreach ($applicable as $name) {
        $component->assertSee('value="'.$name.'"', false);
    }
    $component->assertSee('Cancel Sales')->assertSet('permissions', $this->role->permissions->pluck('name')->all());
    expect($this->admin->can('sales.cancel'))->toBeTrue()->and($this->admin->can('sales.discount'))->toBeFalse()
        ->and($this->admin->can('sales.refund'))->toBeFalse()->and($this->admin->can('nonexistent.business.ability'))->toBeFalse();
});

test('super admin cancellation can be revoked and granted through the original editor and survives a fresh login', function () {
    $before = $this->sale->fresh()->getAttributes();
    $component = Volt::test('roles.index')->call('editRole', $this->role->id)->set('permissions', ['roles.manage', 'sales.view', 'sales.create'])->call('save')->assertHasNoErrors();
    auth()->logout();
    expect(auth('web')->attempt(['email' => $this->admin->email, 'password' => 'password']))->toBeTrue();
    $this->actingAs(auth('web')->user());
    $component = Volt::test('roles.index')->call('editRole', $this->role->id)->assertSet('permissions', ['roles.manage', 'sales.view', 'sales.create']);
    expect($this->admin->fresh()->can('sales.cancel'))->toBeFalse();
    $this->get(route('sales.cancel', $this->sale))->assertForbidden();
    $this->get(route('sales.index', ['view' => 'invoices']))->assertOk()->assertDontSee(route('sales.cancel', $this->sale), false);
    expect(fn () => app(InventoryService::class)->cancelSale($this->sale->id, $this->admin->id))->toThrow(HttpException::class);
    expect($this->sale->fresh()->getAttributes())->toBe($before);
    $component->set('permissions', ['roles.manage', 'sales.view', 'sales.create', 'sales.cancel'])->call('save')->assertHasNoErrors();
    $this->get(route('sales.index', ['view' => 'invoices']))->assertOk()->assertSee(route('sales.cancel', $this->sale), false);
});

test('legacy edit sales cannot authorize cancellation and hydrated actions recheck revocations', function () {
    $component = Volt::test('sales.cancel', ['sale' => $this->sale]);
    $this->role->givePermissionTo('edit sales');
    $this->role->revokePermissionTo('sales.cancel');
    $this->actingAs($this->admin->fresh());
    expect($this->admin->fresh()->can('sales.cancel'))->toBeFalse();
    $component->call('cancelSale')->assertForbidden();
    expect($this->sale->fresh()->status)->toBe('completed');
});

test('a separately assigned direct cancellation permission remains an explicit independent grant', function () {
    $this->role->revokePermissionTo('sales.cancel');
    $this->admin->givePermissionTo('sales.cancel');
    expect($this->role->fresh()->hasPermissionTo('sales.cancel'))->toBeFalse()->and($this->admin->fresh()->can('sales.cancel'))->toBeTrue();
    $this->admin->revokePermissionTo('sales.cancel');
    expect($this->admin->fresh()->can('sales.cancel'))->toBeFalse();
});

test('authorized non admin cancellation restores base stock and preserves receipts and cancellation audit', function () {
    $role = Role::create(['company_id' => $this->company->id, 'name' => 'Cancellation officer', 'guard_name' => 'web', 'sales_scope' => 'branch']);
    $role->syncPermissions(['sales.view', 'sales.cancel']);
    $user = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $user->assignRole($role);
    $payments = $this->sale->payments()->get()->toJson();
    $this->actingAs($user);
    $this->get(route('sales.index', ['view' => 'invoices']))->assertOk()->assertSee(route('sales.cancel', $this->sale), false);
    Volt::test('sales.cancel', ['sale' => $this->sale])->call('cancelSale')->assertRedirect(route('sales.index'));
    $return = StockMovement::where('reference_type', Sale::class)->where('reference_id', $this->sale->id)->where('movement_type', 'return_in')->sole();
    expect((float) $return->quantity_in)->toBe(4.0)->and((float) $return->unit_cost)->toBe(10.0)
        ->and($this->sale->fresh()->cancelled_by)->toBe($user->id)->and($this->sale->fresh()->cancelled_at)->not->toBeNull()
        ->and((float) $this->customer->fresh()->balance_amount)->toBe(0.0)
        ->and($this->sale->payments()->get()->toJson())->toBe($payments);
    expect(fn () => app(InventoryService::class)->cancelSale($this->sale->id, $user->id))->toThrow(ValidationException::class);
    expect(StockMovement::where('reference_type', Sale::class)->where('reference_id', $this->sale->id)->count())->toBe(1);
});

test('cross company and branch cancellation are denied even with the canonical permission', function () {
    $role = Role::create(['company_id' => $this->company->id, 'name' => 'Branch canceller', 'guard_name' => 'web']);
    $role->syncPermissions(['sales.view', 'sales.cancel']);
    $user = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->otherBranch->id, 'status' => 'active']);
    $user->assignRole($role);
    auth()->logout(); // Build a genuinely foreign fixture without the tenant write scope.
    $foreign = User::factory()->create(['company_id' => $this->foreignCompany->id, 'status' => 'active']);
    $foreign->givePermissionTo(['sales.view', 'sales.cancel']);
    expect($foreign->company_id)->toBe($this->foreignCompany->id);
    foreach ([$user, $foreign] as $actor) {
        $this->actingAs($actor)->get(route('sales.cancel', $this->sale))->assertNotFound();
        Volt::test('sales.cancel', ['sale' => $this->sale])->assertForbidden();
        expect(fn () => app(InventoryService::class)->cancelSale($this->sale->id, $actor->id))->toThrow(ModelNotFoundException::class);
    }
    expect($this->sale->fresh()->status)->toBe('completed')->and(StockMovement::count())->toBe(0);
});

test('an authenticated caller cannot impersonate a permitted cancellation actor in the service', function () {
    $user = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $user->givePermissionTo('sales.view');
    $this->actingAs($user);
    expect(fn () => app(InventoryService::class)->cancelSale($this->sale->id, $this->admin->id))->toThrow(HttpException::class);
    expect($this->sale->fresh()->status)->toBe('completed')->and(StockMovement::count())->toBe(0);
});

test('cancellation sale state cannot be replaced in forged Livewire payloads', function () {
    $component = Volt::test('sales.cancel', ['sale' => $this->sale]);
    expect(fn () => $component->set('sale', null))->toThrow(CannotUpdateLockedPropertyException::class);
});

test('delegated role managers cannot edit create or assign Super Admin access', function () {
    $user = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $user->givePermissionTo('roles.manage');
    Permission::findOrCreate('users.edit', 'web');
    $user->givePermissionTo('users.edit');
    $this->actingAs($user);
    Volt::test('roles.index')->call('editRole', $this->role->id)->assertForbidden();
    Volt::test('users.edit', ['user' => $this->admin])->assertForbidden();
    expect(fn () => app(CompanyRoleService::class)->save($user, $this->role->id, ['name' => 'Super Admin'], ['sales.cancel']))->toThrow(HttpException::class);
    Volt::test('roles.index')->set('name', 'Super Admin')->call('save')->assertForbidden();
    expect(fn () => $user->assignRole($this->role))->toThrow(ValidationException::class);
    expect($this->admin->fresh()->roles->pluck('id')->all())->toBe([$this->role->id]);
});

test('last administrator protection rolls back revocation and console recovery restores only role management', function () {
    Volt::test('roles.index')->call('editRole', $this->role->id)->set('permissions', ['sales.view'])->call('save')->assertHasErrors('permissions');
    expect($this->role->fresh()->hasPermissionTo('roles.manage'))->toBeTrue();
    $this->role->syncPermissions(['sales.view']); // Simulate a pre-existing lockout in the disposable fixture.
    $this->actingAs($this->admin->fresh())->get('/roles')->assertForbidden();
    $this->artisan('roles:recover-admin', ['company' => $this->foreignCompany->id, 'user' => $this->admin->id, '--force' => true])->assertFailed();
    $this->artisan('roles:recover-admin', ['company' => $this->company->id, 'user' => $this->admin->id])->assertFailed();
    $this->artisan('roles:recover-admin', ['company' => $this->company->id, 'user' => $this->admin->id, '--force' => true])->assertSuccessful();
    $this->actingAs($this->admin->fresh())->get('/roles')->assertOk();
    expect($this->admin->fresh()->can('sales.cancel'))->toBeFalse()->and($this->admin->fresh()->getDirectPermissions()->pluck('name')->all())->toBe(['roles.manage']);
});

test('recovery refuses ordinary inactive missing and foreign-role users without changing grants', function () {
    $ordinary = User::factory()->create(['company_id' => $this->company->id, 'status' => 'active']);
    $before = DB::table('model_has_permissions')->get()->toJson();
    foreach ([$ordinary->id, 999999] as $id) {
        $this->artisan('roles:recover-admin', ['company' => $this->company->id, 'user' => $id, '--force' => true])->assertFailed();
    }
    $this->admin->update(['status' => 'inactive']);
    $this->artisan('roles:recover-admin', ['company' => $this->company->id, 'user' => $this->admin->id, '--force' => true])->assertFailed();
    expect(DB::table('model_has_permissions')->get()->toJson())->toBe($before);
    $this->get('/roles/recover-admin')->assertNotFound();
});

test('role and owner seeders preserve revoked permissions existing user records and assignments', function () {
    $template = Role::query()->firstOrCreate(['company_id' => null, 'name' => 'Super Admin', 'guard_name' => 'web']);
    $template->syncPermissions(['roles.manage']);
    $this->role->revokePermissionTo('sales.cancel');
    $roles = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
    $assignments = DB::table('model_has_roles')->orderBy('role_id')->orderBy('model_id')->get()->toJson();
    // Reuse an existing owner so the seeder must not reset its password or roles.
    $this->admin->update(['email' => env('SYSTEM_OWNER_EMAIL', 'admin@buildmart.test')]);
    $before = $this->admin->fresh()->getAttributes();
    $this->seed(SuperAdminSeeder::class);
    $this->seed(SuperAdminSeeder::class);
    expect($this->admin->fresh()->getAttributes())->toBe($before)
        ->and(DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson())->toBe($roles)
        ->and(DB::table('model_has_roles')->orderBy('role_id')->orderBy('model_id')->get()->toJson())->toBe($assignments);
    $this->seed(RolePermissionSeeder::class);
    expect($template->fresh()->hasPermissionTo('sales.cancel'))->toBeFalse()->and($this->role->fresh()->hasPermissionTo('sales.cancel'))->toBeFalse();
});

test('super admin backdating follows its checkbox permission without identity bypass', function () {
    expect(app(PosSaleDate::class)->canBackdate($this->admin))->toBeFalse();
    $this->role->givePermissionTo('sales.backdate');
    expect(app(PosSaleDate::class)->canBackdate($this->admin->fresh()))->toBeTrue();
    $this->role->revokePermissionTo('sales.backdate');
    expect(app(PosSaleDate::class)->canBackdate($this->admin->fresh()))->toBeFalse();
});

test('registered permission audit is read only and reports no missing literal checks after registration', function () {
    $this->seed(RolePermissionSeeder::class);
    $before = DB::table('permissions')->orderBy('id')->get()->toJson();
    $status = Artisan::call('permissions:audit');
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($result['missing'])->toBe([])->and($status)->toBe(0)
        ->and(array_values(array_diff($result['required_administration_abilities'], $result['registered_permissions'])))->toBe([])
        ->and(DB::table('permissions')->orderBy('id')->get()->toJson())->toBe($before);
});

test('revoked super admin creation and credit permissions cannot be bypassed through the sale service', function () {
    $cart = [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 50]];
    $inventory = app(InventoryService::class);
    $this->role->revokePermissionTo('sales.create');
    expect(fn () => $inventory->completeSale($cart, [['payment_method' => 'cash', 'amount' => 50]], null, $this->location->id, $this->branch->id, $this->admin->id))->toThrow(HttpException::class);
    $this->role->givePermissionTo('sales.create');
    expect(fn () => $inventory->completeSale($cart, [['payment_method' => 'credit', 'amount' => 50]], $this->customer->id, $this->location->id, $this->branch->id, $this->admin->id))->toThrow(HttpException::class);
    expect(Sale::count())->toBe(1)->and(StockMovement::count())->toBe(0);
});

test('company settings checks honor revoked permissions for super admin on routes and Livewire actions', function () {
    $this->get(route('settings.company'))->assertForbidden();
    $this->role->givePermissionTo('company-settings.update');
    $this->actingAs($this->admin->fresh());
    $component = Volt::test('settings.company');
    $this->role->revokePermissionTo('company-settings.update');
    $this->actingAs($this->admin->fresh());
    $component->call('save')->assertForbidden();
    $this->get(route('settings.company'))->assertForbidden();
    $this->role->givePermissionTo('company-settings.update');
    auth()->logout();
    $this->admin->update(['company_id' => null]);
    $this->actingAs($this->admin->fresh())->get(route('settings.company'))->assertForbidden();
});
