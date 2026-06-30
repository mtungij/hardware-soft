<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $branch = Branch::query()->where('code', 'MAIN')->first();
        $role = Role::query()->firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $role->syncPermissions(Permission::where('guard_name', 'web')->get());

        $ownerEmail = env('SYSTEM_OWNER_EMAIL', 'admin@buildmart.test');

        User::query()
            ->where('is_system_owner', true)
            ->where('email', '!=', $ownerEmail)
            ->update(['is_system_owner' => false]);

        $user = User::query()->updateOrCreate(
            ['email' => $ownerEmail],
            [
                'company_id' => $branch?->company_id,
                'branch_id' => $branch?->id,
                'name' => 'Super Admin',
                'phone' => '+255 700 000 001',
                'status' => 'active',
                'is_system_owner' => true,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $user->syncRoles([$role]);
    }
}
