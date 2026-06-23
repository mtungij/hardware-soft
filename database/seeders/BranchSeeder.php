<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        Branch::query()->firstOrCreate(
            ['code' => 'MAIN'],
            [
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
