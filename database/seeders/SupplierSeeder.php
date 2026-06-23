<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::query()->where('code', 'MAIN')->first();

        $suppliers = [
            ['Twiga Cement Ltd', 'Asha Mrema', '+255 700 100 001', 'sales@twiga.test', 'Dar es Salaam'],
            ['Steel Masters Co.', 'Juma Ally', '+255 700 100 002', 'orders@steelmasters.test', 'Coast'],
            ['Prime Paints Tanzania', 'Neema John', '+255 700 100 003', 'info@primepaints.test', 'Dar es Salaam'],
        ];

        foreach ($suppliers as [$name, $contactPerson, $phone, $email, $region]) {
            Supplier::query()->firstOrCreate(
                ['phone' => $phone],
                [
                    'branch_id' => $branch?->id,
                    'name' => $name,
                    'contact_person' => $contactPerson,
                    'email' => $email,
                    'address' => "{$region} supplier depot",
                    'region' => $region,
                    'opening_balance' => 0,
                    'status' => 'active',
                ]
            );
        }
    }
}
