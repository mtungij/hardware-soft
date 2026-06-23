<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\StockLocation;
use Illuminate\Database\Seeder;

class StockLocationSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::query()->where('code', 'MAIN')->first();

        foreach ([
            ['Main Store', 'MAIN-STORE', 'store'],
            ['Dispensing Area', 'DISPENSING', 'dispensing'],
        ] as [$name, $code, $type]) {
            StockLocation::query()->firstOrCreate(
                ['branch_id' => $branch?->id, 'code' => $code],
                [
                    'name' => $name,
                    'type' => $type,
                    'status' => 'active',
                ]
            );
        }
    }
}
