<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\CompanyRoleService;
use App\Support\RolePermissionCatalog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->company = Company::create(['company_name' => 'Role company A', 'business_type' => 'hardware', 'phone' => '255700000001', 'whatsapp_number' => '255700000001']);
    $this->otherCompany = Company::create(['company_name' => 'Role company B', 'business_type' => 'hardware', 'phone' => '255700000002', 'whatsapp_number' => '255700000002']);
    $this->branch = Branch::create(['company_id' => $this->company->id, 'name' => 'Main', 'code' => 'MAIN']);
    Setting::create(['company_id' => $this->company->id, 'company_name' => 'Role company A']);
    foreach (['roles.manage', 'sales.create', 'sales.discount', 'products.view', 'reports.sales', 'purchases.receive', 'stock.adjust', 'custom.new_capability'] as $name) {
        Permission::findOrCreate($name, 'web');
    }
    Permission::create(['name' => 'api.only', 'guard_name' => 'api']);
    $this->template = Role::query()->firstOrCreate(['name' => 'Cashier', 'guard_name' => 'web', 'company_id' => null]);
    $this->template->syncPermissions(['sales.create', 'products.view']);
    Role::query()->firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web', 'company_id' => null]);
    $this->admin = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $this->admin->assignRole('Super Admin');
    $this->cashier = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $this->cashier->assignRole('Cashier');
    $this->otherCashier = User::factory()->create(['company_id' => $this->otherCompany->id, 'status' => 'active']);
    $this->otherCashier->assignRole('Cashier');
    $this->actingAs($this->admin)->withSession(['staff_locale' => 'en']);
});

test('role editor shows all web permissions by module with existing selections', function () {
    $this->get('/roles')->assertOk();
    Volt::test('roles.index')->call('editRole', $this->template->id)
        ->assertSet('permissions', ['sales.create', 'products.view'])
        ->assertSee('Apply Discount')->assertSee('sales.discount')
        ->assertSee('Custom New Capability')->assertDontSee('api.only');
    expect(RolePermissionCatalog::module('reports.sales'))->toBe('Reports')
        ->and(RolePermissionCatalog::module('sales.discount'))->toBe('Sales / POS')
        ->and(RolePermissionCatalog::groups()->flatten()->count())->toBe(Permission::where('guard_name', 'web')->count());
});

test('granting and revoking role permissions preserves other permissions and other companies', function () {
    $templateId = $this->template->id;
    $component = Volt::test('roles.index')->call('editRole', $templateId)
        ->set('permissions', ['sales.create', 'products.view', 'sales.discount', 'purchases.receive'])
        ->call('save')->assertHasNoErrors();
    $local = Role::namedForCompany('Cashier', $this->company->id);
    expect($local->id)->not->toBe($templateId)
        ->and($local->source_role_id)->toBe($templateId)
        ->and($this->cashier->fresh()->can('sales.discount'))->toBeTrue()
        ->and($this->cashier->fresh()->hasRole('Cashier'))->toBeTrue()
        ->and($this->otherCashier->fresh()->can('sales.discount'))->toBeFalse()
        ->and($this->otherCashier->fresh()->roles->first()->id)->toBe($templateId)
        ->and($this->template->fresh()->permissions->pluck('name')->all())->toBe(['sales.create', 'products.view']);
    $component->call('editRole', $templateId)
        ->assertSet('permissions', ['sales.create', 'products.view', 'sales.discount', 'purchases.receive'])
        ->set('permissions', ['sales.create', 'products.view', 'purchases.receive'])
        ->call('save')->assertHasNoErrors();
    expect($this->cashier->fresh()->can('sales.discount'))->toBeFalse()
        ->and($this->cashier->fresh()->can('purchases.receive'))->toBeTrue()
        ->and($this->cashier->fresh()->can('products.view'))->toBeTrue()
        ->and(Role::where('company_id', $this->company->id)->where('source_role_id', $templateId)->count())->toBe(1);
});

test('future assignments use the company copy even when given the original role', function () {
    app(CompanyRoleService::class)->save($this->admin, $this->template->id, ['name' => 'Cashier'], ['sales.create', 'sales.discount']);
    $user = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id]);
    $user->assignRole($this->template);
    expect($user->fresh()->can('sales.discount'))->toBeTrue()
        ->and($user->fresh()->roles->first()->company_id)->toBe($this->company->id)
        ->and($this->otherCashier->fresh()->can('sales.discount'))->toBeFalse();
});

test('foreign roles and permissions for other guards cannot be edited', function () {
    $foreign = Role::query()->create(['company_id' => $this->otherCompany->id, 'name' => 'Foreign role', 'guard_name' => 'web']);
    expect(fn () => app(CompanyRoleService::class)->save($this->admin, $foreign->id, ['name' => 'Foreign role'], ['sales.discount']))
        ->toThrow(ModelNotFoundException::class);
    expect(fn () => Volt::test('roles.index')->call('editRole', $foreign->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => Volt::test('roles.index')->call('deleteRole', $foreign->id))->toThrow(ModelNotFoundException::class);
    Volt::test('roles.index')->call('editRole', $this->template->id)->set('permissions', ['api.only'])->call('save')->assertHasErrors('permissions.0');
    expect($foreign->fresh()->permissions)->toHaveCount(0);
});

test('cashiers cannot manage permissions and receive no automatic discount grant', function () {
    $this->actingAs($this->cashier)->get('/roles')->assertForbidden();
    Volt::test('roles.index')->call('editRole', $this->template->id)->assertForbidden();
    expect($this->cashier->fresh()->can('sales.discount'))->toBeFalse();
});

test('creating roles scopes identical names and permissions to each company', function () {
    Role::create(['company_id' => $this->otherCompany->id, 'name' => 'Specialist', 'guard_name' => 'web']);
    Volt::test('roles.index')->set('name', 'Specialist')->set('permissions', ['stock.adjust', 'reports.sales'])->call('save')->assertHasNoErrors();
    expect(Role::where('company_id', $this->company->id)->where('name', 'Specialist')->firstOrFail()->permissions)->toHaveCount(2);
    Volt::test('roles.index')->set('name', 'Specialist')->call('save')->assertHasErrors('name');
});

test('invalid permission saves roll back role copies and preserve assignments', function () {
    $before = $this->cashier->roles()->pluck('roles.id')->all();
    expect(fn () => app(CompanyRoleService::class)->save($this->admin, $this->template->id, ['name' => 'Cashier'], ['api.only']))
        ->toThrow(PermissionDoesNotExist::class);
    expect(Role::where('company_id', $this->company->id)->count())->toBe(0)
        ->and($this->cashier->fresh()->roles()->pluck('roles.id')->all())->toBe($before)
        ->and($this->otherCashier->fresh()->roles->first()->id)->toBe($this->template->id);
});

test('foreign role assignment and duplicate shared role names are rejected', function () {
    $foreign = Role::create(['company_id' => $this->otherCompany->id, 'name' => 'Cashier', 'guard_name' => 'web']);
    expect(fn () => $this->cashier->assignRole($foreign))->toThrow(ValidationException::class);
    Volt::test('roles.index')->set('name', 'Cashier')->set('permissions', ['sales.discount'])->call('save')->assertHasErrors('name');
    expect($this->cashier->fresh()->roles->pluck('id')->all())->toBe([$this->template->id]);
});
