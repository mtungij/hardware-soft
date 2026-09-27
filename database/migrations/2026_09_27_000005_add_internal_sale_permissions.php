<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'internal_sales.view', 'internal_sales.create', 'internal_sales.complete',
        'internal_sales.cancel', 'internal_sales.override_price',
        'location_prices.view', 'location_prices.manage',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        foreach (['Super Admin', 'Admin', 'Manager'] as $name) {
            $role = Role::where('name', $name)->where('guard_name', 'web')->first();
            $role?->givePermissionTo(self::PERMISSIONS);
        }
        Role::where('name', 'Store Keeper')->where('guard_name', 'web')->first()
            ?->givePermissionTo(['internal_sales.view', 'internal_sales.create', 'internal_sales.complete', 'location_prices.view']);
        Role::where('name', 'Accountant')->where('guard_name', 'web')->first()
            ?->givePermissionTo(['internal_sales.view', 'location_prices.view']);
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
