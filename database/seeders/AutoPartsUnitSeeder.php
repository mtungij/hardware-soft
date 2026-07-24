<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class AutoPartsUnitSeeder extends Seeder
{
    public function __construct(
        private ?int $companyId = null,
        private ?int $branchId = null,
    ) {}

    public function run(): void
    {
        $units = [
            ['Piece', 'pcs'],
            ['Set', 'set'],
            ['Pair', 'pair'],
            ['Litre', 'L'],
            ['Gallon', 'gal'],
            ['Box', 'box'],
            ['Pack', 'pack'],
            ['Tyre', 'tyre'],
        ];

        foreach ($this->companies() as $company) {
            $branch = $this->branchFor($company);

            foreach ($units as [$name, $shortName]) {
                Unit::query()->updateOrCreate(
                    ['company_id' => $company->id, 'short_name' => $shortName],
                    [
                        'name' => $name,
                        'description' => "{$name} spare parts inventory unit for {$branch?->name}",
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
