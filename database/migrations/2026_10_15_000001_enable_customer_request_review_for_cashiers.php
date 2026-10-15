<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', [
            'customer_requests.view', 'customer_requests.review', 'customer_requests.create_quotation', 'customer_requests.convert_to_sale',
            'quotations.view', 'quotations.create', 'quotations.send', 'quotations.convert', 'invoices.view',
        ])->pluck('id');
        $roles = DB::table('roles')->where('guard_name', 'web')->where('name', 'Cashier')->pluck('id');
        foreach ($roles as $role) {
            foreach ($permissions as $permission) {
                DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // These existing permissions may also have been explicitly assigned by an
        // administrator. Preserve those grants when rolling back the UI change.
    }
};
