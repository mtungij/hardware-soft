<?php

namespace App\Services;

use App\Models\LocationPrice;
use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\StockLocation;
use Illuminate\Validation\ValidationException;

class LocationPriceService
{
    public function priceFor(Product $product, StockLocation $location, string $mode, ?ProductUnitConversion $conversion = null): ?float
    {
        if ($mode === 'internal') {
            return $this->internalPriceFor($product, $location, $conversion)['price'];
        }

        $column = match ($mode) {
            'retail' => 'retail_price',
            'wholesale' => 'wholesale_price',
            default => throw new \InvalidArgumentException('Unknown price mode.'),
        };
        $this->validateContext($product, $location, $conversion);

        $override = LocationPrice::query()
            ->where('company_id', $product->company_id)
            ->where('product_id', $product->id)
            ->where('stock_location_id', $location->id)
            ->where('unit_key', $conversion?->id ?? 0)
            ->where('is_active', true)
            ->first();

        if ($override && $override->{$column} !== null) {
            return (float) $override->{$column};
        }

        if ($conversion) {
            return $conversion->priceFor($mode);
        }

        $default = $mode === 'wholesale' ? $product->wholesale_price : $product->selling_price;

        return $default === null ? null : (float) $default;
    }

    /** @return array{price: ?float, source: ?string} */
    public function internalPriceFor(Product $product, ?StockLocation $location = null, ?ProductUnitConversion $conversion = null): array
    {
        $this->validateContext($product, $location, $conversion);

        if ($location) {
            $override = LocationPrice::query()
                ->where('company_id', $product->company_id)
                ->where('product_id', $product->id)
                ->where('stock_location_id', $location->id)
                ->where('unit_key', $conversion?->id ?? 0)
                ->where('is_active', true)
                ->value('internal_sale_price');

            if ($override !== null) {
                return ['price' => (float) $override, 'source' => $location->name.' Internal Price'];
            }
        }

        // An alternative unit uses its own explicit retail price. The existing unit
        // pricing behavior leaves it blank when no unit-specific price is configured.
        if ($conversion) {
            $price = $conversion->priceFor('retail');

            return ['price' => $price !== null && $price > 0 ? $price : null,
                'source' => $price !== null && $price > 0 ? 'Product Unit Default Price' : null];
        }

        $price = $product->selling_price === null ? null : (float) $product->selling_price;

        return ['price' => $price !== null && $price > 0 ? $price : null,
            'source' => $price !== null && $price > 0 ? 'Product Default Selling Price' : null];
    }

    private function validateContext(Product $product, ?StockLocation $location, ?ProductUnitConversion $conversion): void
    {
        if (($location && (int) $product->company_id !== (int) $location->company_id)
            || ($conversion && ((int) $conversion->product_id !== (int) $product->id
                || (int) $conversion->company_id !== (int) $product->company_id))) {
            throw ValidationException::withMessages(['price' => 'Product, unit and location must belong to the same company.']);
        }
    }

    public function savePrices(Product $product, StockLocation $location, ?ProductUnitConversion $conversion, array $values): LocationPrice
    {
        if ((int) $product->company_id !== (int) $location->company_id
            || ($conversion && ((int) $conversion->company_id !== (int) $product->company_id
                || (int) $conversion->product_id !== (int) $product->id))) {
            throw ValidationException::withMessages(['location_price' => 'Product, unit and location must belong to the same company.']);
        }

        return LocationPrice::query()->updateOrCreate([
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'stock_location_id' => $location->id,
            'unit_key' => $conversion?->id ?? 0,
        ], [
            'product_unit_conversion_id' => $conversion?->id,
            'retail_price' => $values['retail_price'] ?? null,
            'wholesale_price' => $values['wholesale_price'] ?? null,
            'internal_sale_price' => $values['internal_sale_price'] ?? null,
            'is_active' => $values['is_active'] ?? true,
        ]);
    }
}
