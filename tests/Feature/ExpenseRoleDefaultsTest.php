<?php

use App\Models\Company;
use App\Models\Role;
use App\Services\CompanyRoleService;
use App\Support\RolePermissionCatalog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->company = Company::create(['company_name' => 'New expense business', 'business_type' => 'hardware', 'phone' => '255700000001', 'whatsapp_number' => '255700000001']);
    $this->otherCompany = Company::create(['company_name' => 'Other expense business', 'business_type' => 'hardware', 'phone' => '255700000002', 'whatsapp_number' => '255700000002']);
    $this->seed(RolePermissionSeeder::class);
});

test('new standard roles get create expenses while all expense permissions appear in their module', function () {
    $roles = app(CompanyRoleService::class)->provision($this->company);
    expect($roles)->toHaveCount(count(CompanyRoleService::STANDARD_ROLES));
    foreach ($roles as $role) {
        expect($role->company_id)->toBe($this->company->id)
            ->and($role->hasPermissionTo('create expenses'))->toBeTrue()
            ->and($role->source_role_id)->not->toBeNull();
    }
    $expenses = RolePermissionCatalog::groups()->get('Expenses')->pluck('name')->all();
    expect($expenses)->toContain('view expenses', 'create expenses', 'edit expenses', 'approve expenses', 'delete expenses', 'export expenses');
});

test('repeated seeding and provisioning preserve revoked and empty company roles and shared templates', function () {
    $service = app(CompanyRoleService::class);
    $roles = $service->provision($this->company);
    $cashier = $roles->firstWhere('name', 'Cashier');
    $cashier->syncPermissions([]);
    $roles->firstWhere('name', 'Store Keeper')->revokePermissionTo('create expenses');
    $template = Role::whereNull('company_id')->where('name', 'Cashier')->firstOrFail();
    $template->syncPermissions([]);
    $beforeRoles = DB::table('roles')->orderBy('id')->get()->toJson();
    $beforeGrants = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
    $this->seed(RolePermissionSeeder::class);
    $service->provision($this->company);
    expect(DB::table('roles')->orderBy('id')->get()->toJson())->toBe($beforeRoles)
        ->and(DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson())->toBe($beforeGrants)
        ->and($cashier->fresh()->permissions)->toHaveCount(0)
        ->and($template->fresh()->permissions)->toHaveCount(0);
    $other = $service->provision($this->otherCompany)->firstWhere('name', 'Cashier');
    expect($other->hasPermissionTo('create expenses'))->toBeTrue()
        ->and($other->company_id)->toBe($this->otherCompany->id)
        ->and($cashier->fresh()->hasPermissionTo('create expenses'))->toBeFalse();
});
