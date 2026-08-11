<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'company_id',
    'product_id',
    'unit_id',
    'conversion_factor',
    'retail_price',
    'wholesale_price',
    'purchase_price',
    'can_purchase',
    'can_sell',
    'active',
])]
class ProductUnitConversion extends Model
{
    use HasCompany, HasFactory;

    protected static function booted(): void
    {
        static::saving(function (ProductUnitConversion $conversion): void {
            $product = Product::withoutGlobalScopes()->find($conversion->product_id);
            $unit = Unit::withoutGlobalScopes()->find($conversion->unit_id);

            if (! $product || ! $unit || (int) $product->company_id !== (int) $conversion->company_id || (int) $unit->company_id !== (int) $conversion->company_id) {
                throw ValidationException::withMessages(['unit_id' => 'Product and unit must belong to the same company.']);
            }

            if ((int) $product->unit_id === (int) $unit->id) {
                throw ValidationException::withMessages(['unit_id' => 'The base stock unit cannot be configured as an alternative unit.']);
            }

            if ((float) $conversion->conversion_factor <= 0) {
                throw ValidationException::withMessages(['conversion_factor' => 'Conversion factor must be greater than zero.']);
            }

            if ($unit->measurement_type_id && $product->measurement_type_id
                && (int) $unit->measurement_type_id !== (int) $product->measurement_type_id
                && $unit->measurementType()->value('code') !== MeasurementType::COUNT) {
                throw ValidationException::withMessages(['unit_id' => 'The alternative unit is incompatible with the product measurement type.']);
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function baseQuantity(float $transactionQuantity): float
    {
        return round($transactionQuantity * (float) $this->conversion_factor, 4);
    }

    public function priceFor(string $mode): ?float
    {
        $price = $mode === 'wholesale' ? $this->wholesale_price : $this->retail_price;

        return $price === null ? null : (float) $price;
    }

    protected function casts(): array
    {
        return [
            'conversion_factor' => 'decimal:4',
            'retail_price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'purchase_price' => 'decimal:2',
            'can_purchase' => 'boolean',
            'can_sell' => 'boolean',
            'active' => 'boolean',
        ];
    }
}
