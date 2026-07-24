<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\MeasurementType;
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
            ['Simba Cement 50kg', 'BM-CEM-050', 'CEM', 'bag', 'bag', MeasurementType::COUNT, 1, false, 'Simba', '50kg', 15000, 18000, 10],
            ['Nondo Y12', 'BM-NON-Y12', 'NON', 'pcs', 'm', MeasurementType::LENGTH, 12, true, null, 'Y12', 18500, 22000, 20],
            ['Mabati Gauge 28', 'BM-MAB-G28', 'MAB', 'pcs', 'pcs', MeasurementType::COUNT, 1, false, null, 'Gauge 28', 24000, 29500, 15],
            ['Ceramic Floor Tiles 40x40', 'BM-TIL-4040', 'TIL', 'box', 'box', MeasurementType::AREA, 1, true, null, '40x40', 28000, 36000, 8],
            ['Gloss Paint 4L', 'BM-RAN-004', 'RAN', 'L', 'L', MeasurementType::VOLUME, 1, false, null, '4L', 32000, 42000, 6],
            ['PVC Pipe 1 Inch', 'BM-PLU-PVC1', 'PLU', 'pcs', 'm', MeasurementType::LENGTH, 3, true, null, '1 inch', 6500, 9000, 20],
            ['Electrical Cable 2.5mm Roll', 'BM-ELE-25R', 'ELE', 'roll', 'm', MeasurementType::LENGTH, 100, true, null, '2.5mm', 95000, 125000, 4],
            ['Claw Hammer', 'BM-TOL-HMR', 'TOL', 'pcs', 'pcs', MeasurementType::COUNT, 1, false, null, '16oz', 12000, 18000, 10],
        ];

        foreach ($this->companies() as $company) {
            $branch = $this->branchFor($company);

            if (! $branch) {
                continue;
            }

            (new HardwareCategorySeeder($company->id, $branch->id))->run();
            (new HardwareUnitSeeder($company->id, $branch->id))->run();

            foreach ($products as [$name, $sku, $categoryCode, $unitShortName, $sellingUnitShortName, $measurementCode, $conversionFactor, $allowFraction, $brand, $modelSize, $buyingPrice, $sellingPrice, $reorderLevel]) {
                $category = Category::query()->where('company_id', $company->id)->where('code', $categoryCode)->firstOrFail();
                $unit = Unit::query()->where('company_id', $company->id)->where('short_name', $unitShortName)->firstOrFail();
                $sellingUnit = Unit::query()->where('company_id', $company->id)->where('short_name', $sellingUnitShortName)->firstOrFail();
                $measurementType = MeasurementType::query()->where('code', $measurementCode)->firstOrFail();

                Product::query()->updateOrCreate(
                    ['company_id' => $company->id, 'sku' => $sku],
                    [
                        'branch_id' => $branch->id,
                        'category_id' => $category->id,
                        'measurement_type_id' => $measurementType->id,
                        'unit_id' => $unit->id,
                        'selling_unit_id' => $sellingUnit->id,
                        'name' => $name,
                        'barcode' => null,
                        'brand' => $brand,
                        'model_size' => $modelSize,
                        'buying_price' => $buyingPrice,
                        'selling_price' => $sellingPrice,
                        'wholesale_price' => null,
                        'conversion_factor' => $conversionFactor,
                        'allow_fractional_sale' => $allowFraction,
                        'minimum_sale_quantity' => $allowFraction ? 0.25 : 1,
                        'quantity_step' => $allowFraction ? 0.25 : 1,
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
