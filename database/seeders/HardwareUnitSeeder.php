<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use App\Models\MeasurementType;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class HardwareUnitSeeder extends Seeder
{
    public function __construct(
        private ?int $companyId = null,
        private ?int $branchId = null,
    ) {}

    public function run(): void
    {
        $units = [
            ['name' => 'Piece', 'symbol' => 'pcs', 'measurement' => MeasurementType::COUNT],
            ['name' => 'Bag', 'symbol' => 'bag', 'measurement' => MeasurementType::COUNT],
            ['name' => 'Kilogram', 'symbol' => 'kg', 'measurement' => MeasurementType::WEIGHT],
            ['name' => 'Gram', 'symbol' => 'g', 'measurement' => MeasurementType::WEIGHT],
            ['name' => 'Ton', 'symbol' => 'ton', 'measurement' => MeasurementType::WEIGHT],
            ['name' => 'Litre', 'symbol' => 'L', 'code' => 'l', 'measurement' => MeasurementType::VOLUME],
            ['name' => 'Millilitre', 'symbol' => 'ml', 'code' => 'ml', 'measurement' => MeasurementType::VOLUME],
            ['name' => 'Cubic Metre', 'symbol' => 'm³', 'code' => 'm3', 'measurement' => MeasurementType::VOLUME],
            ['name' => 'Cubic Foot', 'symbol' => 'ft³', 'code' => 'ft3', 'measurement' => MeasurementType::VOLUME],
            ['name' => 'Cubic Centimetre', 'symbol' => 'cm³', 'code' => 'cm3', 'measurement' => MeasurementType::VOLUME],
            ['name' => 'Meter', 'symbol' => 'm', 'measurement' => MeasurementType::LENGTH],
            ['name' => 'Centimetre', 'symbol' => 'cm', 'measurement' => MeasurementType::LENGTH],
            ['name' => 'Millimetre', 'symbol' => 'mm', 'measurement' => MeasurementType::LENGTH],
            ['name' => 'Foot', 'symbol' => 'ft', 'measurement' => MeasurementType::LENGTH],
            ['name' => 'Inch', 'symbol' => 'in', 'measurement' => MeasurementType::LENGTH],
            ['name' => 'Square Metre', 'symbol' => 'm²', 'measurement' => MeasurementType::AREA],
            ['name' => 'Square Foot', 'symbol' => 'ft²', 'measurement' => MeasurementType::AREA],
            ['name' => 'Box', 'symbol' => 'box', 'measurement' => MeasurementType::COUNT],
            ['name' => 'Pack', 'symbol' => 'pack', 'measurement' => MeasurementType::COUNT],
            ['name' => 'Bottle', 'symbol' => 'bottle', 'measurement' => MeasurementType::COUNT],
            ['name' => 'Roll', 'symbol' => 'roll', 'measurement' => MeasurementType::COUNT],
            ['name' => 'Bundle', 'symbol' => 'bundle', 'measurement' => MeasurementType::COUNT],
            ['name' => 'Sheet', 'symbol' => 'sheet', 'measurement' => MeasurementType::COUNT],
            ['name' => 'Trip', 'symbol' => 'trip', 'measurement' => MeasurementType::COUNT],
        ];
        $measurementIds = MeasurementType::query()->pluck('id', 'code');

        foreach ($this->companies() as $company) {
            $branch = $this->branchFor($company);

            foreach ($units as $definition) {
                $name = $definition['name'];
                $shortName = $definition['symbol'];
                $code = $definition['code'] ?? null;
                $measurementTypeId = isset($definition['measurement'])
                    ? ($measurementIds[$definition['measurement']] ?? null)
                    : null;

                Unit::query()->updateOrCreate(
                    $code
                        ? ['company_id' => $company->id, 'code' => $code]
                        : ['company_id' => $company->id, 'short_name' => $shortName],
                    [
                        'name' => $name,
                        'code' => $code,
                        'measurement_type_id' => $measurementTypeId,
                        'short_name' => $shortName,
                        'description' => "{$name} inventory unit for {$branch?->name}",
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
