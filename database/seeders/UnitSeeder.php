<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            ['Piece', 'pcs'],
            ['Bag', 'bag'],
            ['Kilogram', 'kg'],
            ['Litre', 'ltr'],
            ['Meter', 'm'],
            ['Box', 'box'],
            ['Roll', 'roll'],
            ['Trip', 'trip'],
        ];

        foreach ($units as [$name, $shortName]) {
            Unit::query()->firstOrCreate(
                ['short_name' => $shortName],
                [
                    'name' => $name,
                    'description' => "{$name} inventory unit",
                    'status' => 'active',
                ]
            );
        }
    }
}
