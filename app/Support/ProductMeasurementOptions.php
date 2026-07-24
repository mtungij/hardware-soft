<?php

namespace App\Support;

use App\Models\MeasurementType;
use App\Models\Unit;
use Illuminate\Support\Collection;

class ProductMeasurementOptions
{
    /**
     * @return Collection<int, Unit>
     */
    public static function compatibleUnits(string $measurementCode, ?int $includeUnitId = null): Collection
    {
        return Unit::query()
            ->with('measurementType')
            ->where(function ($query) use ($includeUnitId): void {
                $query->where('status', 'active');

                if ($includeUnitId) {
                    $query->orWhere('id', $includeUnitId);
                }
            })
            ->orderBy('name')
            ->get()
            ->filter(fn (Unit $unit): bool => self::unitIsAllowed($unit, $measurementCode))
            ->values();
    }

    /**
     * @return Collection<int, Unit>
     */
    public static function baseUnits(string $measurementCode, ?int $includeUnitId = null): Collection
    {
        return self::compatibleUnits($measurementCode, $includeUnitId);
    }

    /**
     * Purchase packaging may intentionally differ from the product measurement type.
     *
     * @return Collection<int, Unit>
     */
    public static function purchaseUnits(string $measurementCode, ?int $includeUnitId = null): Collection
    {
        return Unit::query()
            ->with('measurementType')
            ->where(function ($query) use ($includeUnitId): void {
                $query->where('status', 'active');

                if ($includeUnitId) {
                    $query->orWhere('id', $includeUnitId);
                }
            })
            ->orderBy('name')
            ->get()
            ->filter(fn (Unit $unit): bool => $unit->id === $includeUnitId
                || $unit->measurementType?->code === MeasurementType::COUNT
                || self::unitIsAllowed($unit, $measurementCode))
            ->values();
    }

    /**
     * @return array<int, int>
     */
    public static function purchaseUnitIds(string $measurementCode, ?int $includeUnitId = null): array
    {
        return self::purchaseUnits($measurementCode, $includeUnitId)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @return Collection<int, Unit>
     */
    public static function sellingUnits(string $measurementCode, ?int $includeUnitId = null): Collection
    {
        return self::compatibleUnits($measurementCode, $includeUnitId);
    }

    /**
     * @return array<int, int>
     */
    public static function compatibleUnitIds(string $measurementCode, ?int $includeUnitId = null): array
    {
        return self::compatibleUnits($measurementCode, $includeUnitId)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return array<int, int>
     */
    public static function sellingUnitIds(string $measurementCode, ?int $includeUnitId = null): array
    {
        return self::sellingUnits($measurementCode, $includeUnitId)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return array{unit_id: ?int, purchase_unit_id: ?int, selling_unit_id: ?int, allow_fractional_sale: bool, minimum_sale_quantity: string, quantity_step: string, conversion_factor: string, purchase_conversion_factor: string}
     */
    public static function defaults(string $measurementCode): array
    {
        [$baseAliases, $sellingAliases, $minimum, $step, $conversion, $fractional] = match ($measurementCode) {
            MeasurementType::WEIGHT => [['kg', 'kilogram'], ['kg', 'kilogram'], '0.25', '0.25', '1', true],
            MeasurementType::VOLUME => [['m³', 'm3', 'cubic metre', 'cubic meter'], ['m³', 'm3', 'cubic metre', 'cubic meter'], '0.5', '0.5', '1', true],
            MeasurementType::AREA => [['m²', 'm2', 'square metre', 'square meter'], ['m²', 'm2', 'square metre', 'square meter'], '0.5', '0.5', '1', true],
            MeasurementType::LENGTH => [['metre', 'meter', 'm'], ['metre', 'meter', 'm'], '0.5', '0.5', '1', true],
            MeasurementType::OTHER => [[], [], '1', '1', '1', false],
            default => [['piece', 'pcs', 'pc'], ['piece', 'pcs', 'pc'], '1', '1', '1', false],
        };

        return [
            'unit_id' => self::findUnitId($baseAliases),
            'purchase_unit_id' => self::findUnitId($baseAliases),
            'selling_unit_id' => self::findUnitId($sellingAliases),
            'allow_fractional_sale' => $fractional,
            'minimum_sale_quantity' => $minimum,
            'quantity_step' => $step,
            'conversion_factor' => $conversion,
            'purchase_conversion_factor' => '1',
        ];
    }

    public static function unitIsAllowed(Unit $unit, string $measurementCode): bool
    {
        if ($unit->measurementType?->code) {
            return $unit->measurementType->code === $measurementCode;
        }

        $labels = self::labels($unit);

        $aliases = match ($measurementCode) {
            MeasurementType::WEIGHT => ['kg', 'kgs', 'kilogram', 'kilograms', 'g', 'gram', 'grams', 'ton', 'tons', 'tonne', 'tonnes'],
            MeasurementType::LENGTH => ['m', 'metre', 'metres', 'meter', 'meters', 'ft', 'foot', 'feet', 'pc', 'pcs', 'piece', 'pieces'],
            MeasurementType::VOLUME => ['m³', 'm3', 'cbm', 'cubic metre', 'cubic meter', 'l', 'ltr', 'litre', 'litres', 'liter', 'liters', 'ml', 'millilitre', 'millilitres', 'milliliter', 'milliliters', 'ft³', 'ft3', 'cu ft', 'cubic foot', 'cubic feet', 'cm³', 'cm3', 'cc', 'cubic centimetre', 'cubic centimeter'],
            MeasurementType::AREA => ['m²', 'm2', 'sqm', 'square metre', 'square meter', 'ft²', 'ft2', 'sq ft', 'square foot', 'square feet'],
            MeasurementType::OTHER => [],
            default => ['pc', 'pcs', 'piece', 'pieces', 'bag', 'bags', 'box', 'boxes', 'pack', 'packs', 'bottle', 'bottles', 'roll', 'rolls', 'bundle', 'bundles', 'sheet', 'sheets', 'set', 'sets', 'tyre', 'tyres', 'tire', 'tires', 'trip', 'trips', 'pair', 'pairs', 'bucket', 'buckets'],
        };

        return collect($labels)->contains(fn (string $label): bool => in_array($label, $aliases, true));
    }

    /**
     * @param  array<int, string>  $aliases
     */
    private static function findUnitId(array $aliases): ?int
    {
        return Unit::query()
            ->with('measurementType')
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->first(fn (Unit $unit): bool => collect(self::labels($unit))->contains(
                fn (string $label): bool => in_array($label, $aliases, true),
            ))?->id;
    }

    /**
     * @return array<int, string>
     */
    private static function labels(Unit $unit): array
    {
        return array_values(array_unique(array_filter([
            mb_strtolower(trim((string) $unit->name)),
            mb_strtolower(trim((string) $unit->short_name)),
            mb_strtolower(trim((string) $unit->code)),
        ])));
    }
}
