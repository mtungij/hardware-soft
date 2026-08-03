<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = ['production.view_costing', 'production.manage_costing', 'production.finalize_costing'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::PERMISSIONS as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        Role::query()->whereIn('name', ['Super Admin', 'Admin'])->where('guard_name', 'web')->get()
            ->each(fn (Role $role) => $role->givePermissionTo(self::PERMISSIONS));
        Role::query()->where('name', 'Manager')->where('guard_name', 'web')->get()
            ->each(fn (Role $role) => $role->givePermissionTo(['production.view_costing', 'production.manage_costing']));
        Role::query()->where('name', 'Accountant')->where('guard_name', 'web')->get()
            ->each(fn (Role $role) => $role->givePermissionTo(['production.view_costing', 'production.finalize_costing']));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
