<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use Illuminate\Database\Seeder;

class HardwareCategorySeeder extends Seeder
{
    public function __construct(
        private ?int $companyId = null,
        private ?int $branchId = null,
    ) {}

    public function run(): void
    {
        $categories = [
            ['Cement', 'CEM', 'Cement and concrete materials'],
            ['Nondo', 'NON', 'Reinforcement bars and steel rods'],
            ['Mabati', 'MAB', 'Roofing sheets and accessories'],
            ['Tiles', 'TIL', 'Floor and wall tiles'],
            ['Rangi', 'RAN', 'Paints and finishing materials'],
            ['Plumbing', 'PLU', 'Pipes, fittings, and plumbing materials'],
            ['Electrical', 'ELE', 'Cables, switches, and electrical materials'],
            ['Tools', 'TOL', 'Construction and workshop tools'],
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
                        'allow_fractional_sales' => $code === 'PIP',
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

        return Company::query()->where('business_type', 'Hardware Store')->orderBy('id')->get();
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
