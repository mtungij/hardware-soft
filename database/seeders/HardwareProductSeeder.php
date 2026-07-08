<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class HardwareProductSeeder extends Seeder
{
    public function __construct(
        private ?int $companyId = null,
        private ?int $branchId = null,
    ) {}

    public function run(): void
    {
        $products = [
            ['Simba Cement 50kg', 'BM-CEM-050', 'CEM', 'bag', 'Simba', '50kg', 15000, 18000, 10],
            ['Nondo Y12', 'BM-NON-Y12', 'NON', 'pcs', null, 'Y12', 18500, 22000, 20],
            ['Mabati Gauge 28', 'BM-MAB-G28', 'MAB', 'pcs', null, 'Gauge 28', 24000, 29500, 15],
            ['Ceramic Floor Tiles 40x40', 'BM-TIL-4040', 'TIL', 'box', null, '40x40', 28000, 36000, 8],
            ['Gloss Paint 4L', 'BM-RAN-004', 'RAN', 'ltr', null, '4L', 32000, 42000, 6],
            ['PVC Pipe 1 Inch', 'BM-PLU-PVC1', 'PLU', 'pcs', null, '1 inch', 6500, 9000, 20],
            ['Electrical Cable 2.5mm Roll', 'BM-ELE-25R', 'ELE', 'roll', null, '2.5mm', 95000, 125000, 4],
            ['Claw Hammer', 'BM-TOL-HMR', 'TOL', 'pcs', null, '16oz', 12000, 18000, 10],
        ];

        foreach ($this->companies() as $company) {
            $branch = $this->branchFor($company);

            if (! $branch) {
                continue;
            }

            (new HardwareCategorySeeder($company->id, $branch->id))->run();
            (new HardwareUnitSeeder($company->id, $branch->id))->run();

            foreach ($products as [$name, $sku, $categoryCode, $unitShortName, $brand, $modelSize, $buyingPrice, $sellingPrice, $reorderLevel]) {
                $category = Category::query()->where('company_id', $company->id)->where('code', $categoryCode)->firstOrFail();
                $unit = Unit::query()->where('company_id', $company->id)->where('short_name', $unitShortName)->firstOrFail();

                Product::query()->updateOrCreate(
                    ['company_id' => $company->id, 'sku' => $sku],
                    [
                        'branch_id' => $branch->id,
                        'category_id' => $category->id,
                        'unit_id' => $unit->id,
                        'name' => $name,
                        'barcode' => null,
                        'brand' => $brand,
                        'model_size' => $modelSize,
                        'buying_price' => $buyingPrice,
                        'selling_price' => $sellingPrice,
                        'wholesale_price' => null,
                        'reorder_level' => $reorderLevel,
                        'taxable' => false,
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
