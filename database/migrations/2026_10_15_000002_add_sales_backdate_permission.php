<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore(['name' => 'sales.backdate', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        $permission = DB::table('permissions')->where('name', 'sales.backdate')->where('guard_name', 'web')->value('id');
        foreach (DB::table('roles')->where('guard_name', 'web')->whereIn('name', ['Super Admin', 'Admin'])->pluck('id') as $role) {
            DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Preserve any grants administrators may have explicitly assigned.
    }
};
