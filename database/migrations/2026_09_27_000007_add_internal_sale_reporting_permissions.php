<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'reports.internal_sales', 'reports.internal_acquisitions', 'reports.location_margins',
        'internal_sales.print_delivery_note', 'internal_sales.print_value_note',
        'internal_sales.view_cost', 'internal_sales.view_margin',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        foreach (['Super Admin', 'Admin', 'Manager'] as $name) {
            Role::where('name', $name)->where('guard_name', 'web')->first()?->givePermissionTo(self::PERMISSIONS);
        }
        Role::where('name', 'Store Keeper')->where('guard_name', 'web')->first()
            ?->givePermissionTo(['reports.internal_sales', 'reports.internal_acquisitions', 'internal_sales.print_delivery_note']);
        Role::where('name', 'Accountant')->where('guard_name', 'web')->first()
            ?->givePermissionTo(self::PERMISSIONS);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::where('name', $name)->where('guard_name', 'web')->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
