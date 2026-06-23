<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::query()->where('code', 'MAIN')->first();

        $categories =[
    ['Cement', 'CEM'],
    ['Mabati', 'MAB'],
    ['Nondo', 'NON'],
    ['Rangi', 'RAN'],
    ['Misumari', 'MIS'],
    ['Mabomba', 'PIP'],
    ['Tiles', 'TIL'],
    ['Electrical', 'ELE'],
    ['Plumbing', 'PLU'],
    ['Tools', 'TOL'],

    // New Categories
    ['Tofali', 'TOF'],
    ['Wire Mesh', 'WMS'],
    ['Fence Wire', 'FEN'],
    ['Marine Board', 'MRB'],
    ['Water Tanks', 'WTK'],
    ['Toilet Sinks', 'TSK'],
    ['Kitchen Sinks', 'KSK'],
    ['Hoes', 'HOE'],
    ['Gypsum Board', 'GYP'],
];

        foreach ($categories as [$name, $code]) {
            Category::query()->firstOrCreate(
                ['code' => $code],
                [
                    'branch_id' => $branch?->id,
                    'name' => $name,
                    'description' => "{$name} construction materials",
                    'status' => 'active',
                ]
            );
        }
    }
}
