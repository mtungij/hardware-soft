<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Customer;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::query()->where('code', 'MAIN')->first();

        $customers = [
            ['Walk-in Customer', '+255 700 200 001', null, 'Dar es Salaam', 'cash', 0],
            ['Riverside Contractors', '+255 700 200 002', 'accounts@riverside.test', 'Dar es Salaam', 'contractor', 15000000],
            ['Mikocheni Wholesale Hardware', '+255 700 200 003', 'buyer@mikocheni.test', 'Dar es Salaam', 'wholesale', 25000000],
            ['Credit Builder Group', '+255 700 200 004', 'finance@creditbuilder.test', 'Dodoma', 'credit', 10000000],
        ];

        foreach ($customers as [$name, $phone, $email, $region, $type, $creditLimit]) {
            Customer::query()->firstOrCreate(
                ['phone' => $phone],
                [
                    'branch_id' => $branch?->id,
                    'name' => $name,
                    'email' => $email,
                    'address' => "{$region} customer address",
                    'region' => $region,
                    'customer_type' => $type,
                    'credit_limit' => $creditLimit,
                    'opening_balance' => 0,
                    'status' => 'active',
                ]
            );
        }
    }
}
