<?php

namespace Database\Seeders;

use App\Models\MeasurementType;
use Illuminate\Database\Seeder;

class MeasurementTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => MeasurementType::COUNT, 'name' => 'Count', 'sort_order' => 1],
            ['code' => MeasurementType::LENGTH, 'name' => 'Length', 'sort_order' => 2],
            ['code' => MeasurementType::WEIGHT, 'name' => 'Weight', 'sort_order' => 3],
            ['code' => MeasurementType::AREA, 'name' => 'Area', 'sort_order' => 4],
            ['code' => MeasurementType::VOLUME, 'name' => 'Volume', 'sort_order' => 5],
            ['code' => MeasurementType::OTHER, 'name' => 'Other', 'sort_order' => 6],
        ] as $type) {
            MeasurementType::query()->updateOrCreate(['code' => $type['code']], $type);
        }
    }
}
