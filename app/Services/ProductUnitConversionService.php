<?php

namespace App\Services;

use App\Models\MeasurementType;
use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\Unit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductUnitConversionService
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function sync(Product $product, array $rows): void
    {
        DB::transaction(function () use ($product, $rows): void {
            $kept = [];
            $seenUnitIds = [];

            foreach ($rows as $index => $row) {
                if (blank($row['unit_id'] ?? null)) {
                    continue;
                }

                $unit = Unit::query()->where('company_id', $product->company_id)->find($row['unit_id']);
                $this->validateRow($product, $unit, $row, $index, $seenUnitIds);

                $conversion = ProductUnitConversion::query()->updateOrCreate(
                    ['company_id' => $product->company_id, 'product_id' => $product->id, 'unit_id' => $unit->id],
                    [
                        'conversion_factor' => $row['conversion_factor'],
                        'retail_price' => filled($row['retail_price'] ?? null) ? $row['retail_price'] : null,
                        'wholesale_price' => filled($row['wholesale_price'] ?? null) ? $row['wholesale_price'] : null,
                        'purchase_price' => filled($row['purchase_price'] ?? null) ? $row['purchase_price'] : null,
                        'can_purchase' => (bool) ($row['can_purchase'] ?? false),
                        'can_sell' => (bool) ($row['can_sell'] ?? false),
                        'active' => (bool) ($row['active'] ?? false),
                    ],
                );
                $kept[] = $conversion->id;
                $seenUnitIds[] = $unit->id;
            }

            $product->unitConversions()->when($kept !== [], fn ($query) => $query->whereNotIn('id', $kept))->update(['active' => false]);
        });
    }

    public function purchasable(Product $product): Collection
    {
        return ProductUnitConversion::query()
            ->with('unit')
            ->where('company_id', $product->company_id)
            ->where('product_id', $product->id)
            ->where('active', true)
            ->where('can_purchase', true)
            ->orderBy('id')
            ->get();
    }

    public function sellable(Product $product): Collection
    {
        return $product->unitConversions()->with('unit')->where('active', true)->where('can_sell', true)->orderBy('id')->get();
    }

    public function resolveForPurchase(Product $product, ?int $conversionId, bool $lockForUpdate = false): ?ProductUnitConversion
    {
        return $this->resolve($product, $conversionId, 'can_purchase', $lockForUpdate);
    }

    public function resolveForSale(Product $product, ?int $conversionId): ?ProductUnitConversion
    {
        return $this->resolve($product, $conversionId, 'can_sell');
    }

    /**
     * Normalize a purchase-unit quantity and price to the product's base stock unit.
     *
     * @return array{conversion: ?ProductUnitConversion, transaction_quantity: float, base_quantity: float, conversion_factor: float, transaction_unit_cost: float, base_unit_cost: float}
     */
    public function normalizePurchase(
        Product $product,
        ?int $conversionId,
        mixed $transactionQuantity,
        mixed $transactionUnitCost,
        bool $lockForUpdate = false,
    ): array {
        if (! is_numeric($transactionQuantity) || (float) $transactionQuantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be greater than zero.']);
        }

        if (! is_numeric($transactionUnitCost) || (float) $transactionUnitCost < 0) {
            throw ValidationException::withMessages(['cost_price' => 'Buying Price must be zero or greater.']);
        }

        $quantity = (float) $transactionQuantity;
        $cost = (float) $transactionUnitCost;
        $conversion = $this->resolveForPurchase($product, $conversionId, $lockForUpdate);
        $factor = $conversion ? (float) $conversion->conversion_factor : 1.0;

        if ($factor <= 0) {
            throw ValidationException::withMessages(['product_unit_conversion_id' => 'The selected unit has an invalid conversion factor.']);
        }

        $transactionMeasurement = $conversion?->unit?->measurementType?->code
            ?? $product->unit?->measurementType?->code
            ?? $product->measurementCode();

        if ($transactionMeasurement === MeasurementType::COUNT && ! $product->quantityIsWhole($quantity)) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be a whole number for the selected unit.']);
        }

        $baseQuantity = $conversion ? $conversion->baseQuantity($quantity) : round($quantity, 4);

        if (! $product->acceptsStockQuantity($baseQuantity)) {
            throw ValidationException::withMessages(['quantity' => $product->displayNameWithSize().' must convert to a valid base stock quantity.']);
        }

        return [
            'conversion' => $conversion,
            'transaction_quantity' => $quantity,
            'base_quantity' => $baseQuantity,
            'conversion_factor' => $factor,
            'transaction_unit_cost' => $cost,
            'base_unit_cost' => round($cost / $factor, 4),
        ];
    }

    private function resolve(Product $product, ?int $conversionId, string $flag, bool $lockForUpdate = false): ?ProductUnitConversion
    {
        if (! $conversionId) {
            return null;
        }

        $conversion = ProductUnitConversion::query()
            ->with('unit')
            ->where('company_id', $product->company_id)
            ->where('product_id', $product->id)
            ->where('active', true)
            ->where($flag, true)
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->find($conversionId);

        if (! $conversion) {
            throw ValidationException::withMessages(['unit' => 'The selected product unit is inactive or not allowed for this transaction.']);
        }

        return $conversion;
    }

    /** @param array<string, mixed> $row @param array<int, int> $seenUnitIds */
    private function validateRow(Product $product, ?Unit $unit, array $row, int $index, array $seenUnitIds): void
    {
        $messages = [];
        $key = "unit_conversions.{$index}";

        if (! $unit) {
            $messages["{$key}.unit_id"] = 'Select a unit belonging to this company.';
        } elseif ((int) $unit->id === (int) $product->unit_id) {
            $messages["{$key}.unit_id"] = 'The base stock unit must not be repeated as an alternative unit.';
        } elseif ($unit->measurement_type_id && $product->measurement_type_id
            && (int) $unit->measurement_type_id !== (int) $product->measurement_type_id
            && $unit->measurementType()->value('code') !== MeasurementType::COUNT) {
            $messages["{$key}.unit_id"] = 'The alternative unit is incompatible with the product measurement type.';
        }

        if (! is_numeric($row['conversion_factor'] ?? null) || (float) $row['conversion_factor'] <= 0) {
            $messages["{$key}.conversion_factor"] = 'Conversion factor must be greater than zero.';
        }

        if ($unit && in_array($unit->id, $seenUnitIds, true)) {
            $messages["{$key}.unit_id"] = 'Each alternative unit may only be configured once.';
        }

        foreach (['retail_price', 'wholesale_price', 'purchase_price'] as $price) {
            if (filled($row[$price] ?? null) && (! is_numeric($row[$price]) || (float) $row[$price] < 0)) {
                $messages["{$key}.{$price}"] = 'Price must be zero or greater.';
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }
}
