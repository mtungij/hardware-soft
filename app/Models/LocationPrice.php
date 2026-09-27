<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['company_id', 'product_id', 'stock_location_id', 'product_unit_conversion_id', 'unit_key', 'retail_price', 'wholesale_price', 'internal_sale_price', 'is_active'])]
class LocationPrice extends Model
{
    use HasCompany;

    protected static function booted(): void
    {
        static::saving(function (self $price): void {
            $product = Product::withoutGlobalScopes()->find($price->product_id);
            $location = StockLocation::withoutGlobalScopes()->find($price->stock_location_id);
            $conversion = $price->product_unit_conversion_id
                ? ProductUnitConversion::withoutGlobalScopes()->find($price->product_unit_conversion_id)
                : null;

            if (! $product || ! $location || (int) $product->company_id !== (int) $price->company_id
                || (int) $location->company_id !== (int) $price->company_id
                || ($price->product_unit_conversion_id && (! $conversion
                    || (int) $conversion->company_id !== (int) $price->company_id
                    || (int) $conversion->product_id !== (int) $product->id))) {
                throw ValidationException::withMessages(['location_price' => 'Product, unit and location must belong to the same company.']);
            }

            $price->unit_key = $conversion?->id ?? 0;
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    public function productUnitConversion(): BelongsTo
    {
        return $this->belongsTo(ProductUnitConversion::class);
    }

    protected function casts(): array
    {
        return [
            'retail_price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'internal_sale_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
