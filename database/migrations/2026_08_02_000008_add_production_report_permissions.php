<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'production.view_reports',
        'production.export_reports',
        'production.view_cost_reports',
        'production.view_batch_traceability',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::query()->whereIn('name', ['Super Admin', 'Admin'])->where('guard_name', 'web')->get()
            ->each(fn (Role $role) => $role->givePermissionTo(self::PERMISSIONS));
        Role::query()->where('name', 'Manager')->where('guard_name', 'web')->get()
            ->each(fn (Role $role) => $role->givePermissionTo([
                'production.view_reports',
                'production.export_reports',
                'production.view_batch_traceability',
            ]));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
