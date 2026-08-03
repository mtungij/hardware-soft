<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'production.view_quality', 'production.manage_quality_plans',
        'production.perform_quality_inspections', 'production.approve_quality',
        'production.manage_quality_holds',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::PERMISSIONS as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        Role::query()->whereIn('name', ['Super Admin', 'Admin'])->where('guard_name', 'web')->get()
            ->each(fn (Role $role) => $role->givePermissionTo(self::PERMISSIONS));
        Role::query()->where('name', 'Manager')->where('guard_name', 'web')->get()
            ->each(fn (Role $role) => $role->givePermissionTo(['production.view_quality', 'production.perform_quality_inspections', 'production.manage_quality_holds']));
        Role::query()->firstOrCreate(['name' => 'Quality Manager', 'guard_name' => 'web'])->givePermissionTo(self::PERMISSIONS);
        Role::query()->firstOrCreate(['name' => 'Quality Inspector', 'guard_name' => 'web'])
            ->givePermissionTo(['production.view_quality', 'production.perform_quality_inspections']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
