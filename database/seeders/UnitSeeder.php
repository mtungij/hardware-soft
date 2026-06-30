<?php

namespace Database\Seeders;

use App\Models\Company;
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

        foreach ($this->companyIds() as $companyId) {
            foreach ($units as [$name, $shortName]) {
                Unit::query()->updateOrCreate(
                    ['company_id' => $companyId, 'short_name' => $shortName],
                    [
                        'name' => $name,
                        'description' => "{$name} inventory unit",
                        'status' => 'active',
                    ]
                );
            }
        }
    }

    /**
     * Set SEED_COMPANY_ID=3 to seed one company, or omit it to seed all companies.
     *
     * @return array<int>
     */
    private function companyIds(): array
    {
        $companyId = env('SEED_COMPANY_ID');

        if ($companyId) {
            return [Company::query()->findOrFail((int) $companyId)->id];
        }

        return Company::query()->orderBy('id')->pluck('id')->all();
    }
}
