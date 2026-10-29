<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\ManufacturingRoleAccess;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::getDriverName() !== 'sqlite'
    || ! str_starts_with(config('database.connections.sqlite.database'), sys_get_temp_dir().'/hardex-roles-ui-')) {
    throw new RuntimeException('Browser fixtures require an isolated temporary SQLite database.');
}
$company = Company::create(['company_name' => 'Roles UI validation', 'business_type' => 'hardware', 'phone' => '255700000001', 'whatsapp_number' => '255700000001']);
$branch = Branch::create(['company_id' => $company->id, 'code' => 'UI', 'name' => 'Browser branch', 'status' => 'active']);
Setting::create(['company_id' => $company->id, 'company_name' => $company->company_name, 'enable_warehouse' => false, 'inventory_mode' => 'single_location']);
foreach (['roles.manage', 'payment_methods.view', 'payment_methods.manage', 'sales.create', 'sales.discount', 'products.view', 'view expenses', 'create expenses', 'production.view', 'custom.'.str_repeat('long_permission_', 10)] as $name) {
    Permission::findOrCreate($name, 'web');
}
$adminRole = Role::create(['company_id' => $company->id, 'name' => 'Super Admin', 'guard_name' => 'web']);
$adminRole->syncPermissions(Permission::where('guard_name', 'web')->get());
$admin = User::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'status' => 'active']);
$admin->assignRole($adminRole);
$cashier = Role::create(['company_id' => $company->id, 'name' => 'Cashier', 'guard_name' => 'web']);
$cashier->syncPermissions(['sales.create', 'products.view']);
foreach (ManufacturingRoleAccess::ROLES as $name) {
    Role::create(['company_id' => $company->id, 'name' => $name, 'guard_name' => 'web']);
}
echo json_encode(['email' => $admin->email, 'role' => $cashier->id]);
