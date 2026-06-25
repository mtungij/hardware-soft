<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\StockLocation;
use Illuminate\Database\Seeder;

class StockLocationSeeder extends Seeder
{
    public function run(): void
    {
        Branch::query()->each(function (Branch $branch) {
            StockLocation::query()->firstOrCreate(
                [
                    'branch_id' => $branch->id,
                    'code' => 'MAIN-STORE',
                    'type' => 'store',
                ],
                [
                    'name' => 'Main Store',
                    'status' => 'active',
                ]
            );

            StockLocation::query()->firstOrCreate(
                [
                    'branch_id' => $branch->id,
                    'code' => 'DISPENSING',
                    'type' => 'dispensing',
                ],
                [
                    'name' => 'Dispensing Area',
                    'status' => 'active',
                ]
            );
        });
    }
}
