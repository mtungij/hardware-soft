<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use Illuminate\Database\Seeder;

class AutoPartsCategorySeeder extends Seeder
{
    public function __construct(
        private ?int $companyId = null,
        private ?int $branchId = null,
    ) {}

    public function run(): void
    {
        $categories = [
            ['Filters', 'FLT', 'Oil, air, fuel, and cabin filters'],
            ['Brake Parts', 'BRK', 'Brake pads, shoes, discs, and drums'],
            ['Oils & Fluids', 'OIL', 'Engine oils, gear oils, and service fluids'],
            ['Batteries', 'BAT', 'Vehicle batteries and charging parts'],
            ['Tyres', 'TYR', 'Tyres and wheel service items'],
            ['Bearings', 'BRG', 'Wheel and mechanical bearings'],
            ['Suspension', 'SUS', 'Shock absorbers and suspension parts'],
            ['Engine Parts', 'ENG', 'Engine service and replacement parts'],
        ];

        foreach ($this->companies() as $company) {
            $branch = $this->branchFor($company);

            foreach ($categories as [$name, $code, $description]) {
                Category::query()->updateOrCreate(
                    ['company_id' => $company->id, 'code' => $code],
                    [
                        'branch_id' => $branch?->id,
                        'name' => $name,
                        'description' => $description,
                        'status' => 'active',
                    ]
                );
            }
        }
    }

    /**
     * @return iterable<Company>
     */
    private function companies(): iterable
    {
        if ($this->branchId) {
            return [Branch::query()->findOrFail($this->branchId)->company()->firstOrFail()];
        }

        if ($this->companyId) {
            return [Company::query()->findOrFail($this->companyId)];
        }

        $seedCompanyId = env('SEED_COMPANY_ID');

        if ($seedCompanyId) {
            return [Company::query()->findOrFail((int) $seedCompanyId)];
        }

        return Company::query()->where('business_type', 'Auto Spare Parts')->orderBy('id')->get();
    }

    private function branchFor(Company $company): ?Branch
    {
        if ($this->branchId) {
            return Branch::query()->where('company_id', $company->id)->findOrFail($this->branchId);
        }

        return Branch::query()
            ->where('company_id', $company->id)
            ->where('code', 'MAIN')
            ->first()
            ?? Branch::query()->where('company_id', $company->id)->first();
    }
}
