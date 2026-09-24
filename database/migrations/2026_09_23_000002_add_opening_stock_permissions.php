<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        foreach (['opening_stock.view', 'opening_stock.create'] as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        }

        $permissions = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', ['opening_stock.view', 'opening_stock.create'])->pluck('id');
        $roles = DB::table('roles')->where('guard_name', 'web')->whereIn('name', ['Super Admin', 'Admin', 'Manager', 'Store Keeper'])->pluck('id');
        foreach ($roles as $roleId) {
            foreach ($permissions as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->where('guard_name', 'web')->whereIn('name', ['opening_stock.view', 'opening_stock.create'])->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
