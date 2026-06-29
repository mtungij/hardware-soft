<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Setting;
use App\Models\StockLocation;
use Illuminate\Database\Seeder;

class StockLocationSeeder extends Seeder
{
    public function run(): void
    {
        $warehouseEnabled = (bool) (Setting::query()->value('enable_warehouse') ?? true);

        Branch::query()->each(function (Branch $branch) use ($warehouseEnabled) {
            if ($warehouseEnabled) {
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
            } else {
                StockLocation::query()
                    ->where('branch_id', $branch->id)
                    ->where('code', 'MAIN-STORE')
                    ->update(['status' => 'inactive']);
            }

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
