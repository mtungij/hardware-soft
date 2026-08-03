<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'production.view_orders', 'production.create_orders', 'production.execute_orders',
        'production.complete_orders', 'production.cancel_orders',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::PERMISSIONS as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        Role::query()->whereIn('name', ['Super Admin', 'Admin', 'Manager'])->where('guard_name', 'web')->get()
            ->each(fn (Role $role) => $role->givePermissionTo(self::PERMISSIONS));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
