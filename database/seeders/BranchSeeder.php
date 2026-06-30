<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->firstOrCreate(
            ['company_name' => 'Hardex POS'],
            [
                'business_type' => 'Hardware Store',
                'phone' => '+255 700 000 000',
                'whatsapp_number' => '+255 700 000 000',
                'email' => 'info@buildmart.test',
            ]
        );

        Branch::query()->firstOrCreate(
            ['company_id' => $company->id, 'code' => 'MAIN'],
            [
                'company_id' => $company->id,
                'name' => 'Main Branch',
                'phone' => '+255 700 000 000',
                'email' => 'main@buildmart.test',
                'address' => 'Hardex Head Office',
                'region' => 'Dar es Salaam',
                'status' => 'active',
            ]
        );
    }
}
