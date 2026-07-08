<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class AutoPartsProductSeeder extends Seeder
{
    public function __construct(
        private ?int $companyId = null,
        private ?int $branchId = null,
    ) {}

    public function run(): void
    {
        $products = [
            ['Oil Filter Toyota', 'AP-FLT-OIL-TY', 'FLT', 'pcs', 'Toyota', 'Oil filter', 8500, 13000, 12],
            ['Air Filter Nissan', 'AP-FLT-AIR-NS', 'FLT', 'pcs', 'Nissan', 'Air filter', 12000, 18000, 10],
            ['Front Brake Pads', 'AP-BRK-PAD-FR', 'BRK', 'set', null, 'Front set', 38000, 55000, 6],
            ['Engine Oil 5W-30 4L', 'AP-OIL-5W30-4L', 'OIL', 'ltr', null, '5W-30 4L', 52000, 70000, 8],
            ['N70 Car Battery', 'AP-BAT-N70', 'BAT', 'pcs', null, 'N70', 185000, 240000, 3],
            ['Tyre 195/65R15', 'AP-TYR-1956515', 'TYR', 'tyre', null, '195/65R15', 145000, 195000, 4],
            ['Wheel Bearing Kit', 'AP-BRG-WHL-KIT', 'BRG', 'set', null, 'Wheel kit', 28000, 42000, 6],
            ['Shock Absorber Front', 'AP-SUS-SHOCK-FR', 'SUS', 'pcs', null, 'Front', 65000, 90000, 4],
        ];

        foreach ($this->companies() as $company) {
            $branch = $this->branchFor($company);

            if (! $branch) {
                continue;
            }

            (new AutoPartsCategorySeeder($company->id, $branch->id))->run();
            (new AutoPartsUnitSeeder($company->id, $branch->id))->run();

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
