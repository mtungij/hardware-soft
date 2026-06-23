<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::query()->where('code', 'MAIN')->first();

        $user = User::query()->updateOrCreate(
            ['email' => 'admin@buildmart.test'],
            [
                'branch_id' => $branch?->id,
                'name' => 'Super Admin',
                'phone' => '+255 700 000 001',
                'status' => 'active',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $user->assignRole('Super Admin');
    }
}
