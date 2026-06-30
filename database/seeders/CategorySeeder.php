<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
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

        foreach ($this->companyIds() as $companyId) {
            $branch = Branch::query()
                ->where('company_id', $companyId)
                ->where('code', 'MAIN')
                ->first()
                ?? Branch::query()->where('company_id', $companyId)->first();

            foreach ($categories as [$name, $code]) {
                Category::query()->updateOrCreate(
                    ['company_id' => $companyId, 'code' => $code],
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
