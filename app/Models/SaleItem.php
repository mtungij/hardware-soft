<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'sale_id', 'product_id', 'product_unit_conversion_id', 'product_size_id', 'stock_location_id', 'selling_unit_id', 'base_unit_id', 'conversion_factor', 'conversion_factor_to_base', 'selling_unit_name_snapshot', 'selling_unit_code_snapshot', 'base_unit_name_snapshot', 'base_unit_code_snapshot', 'base_unit_cost', 'sold_from_label', 'sale_type', 'quantity', 'base_quantity', 'unit_cost', 'unit_price', 'discount_per_unit', 'discount_amount', 'discount_total', 'gross_total', 'net_unit_price', 'net_total', 'tax_amount', 'line_total'])]
class SaleItem extends Model
{
    use HasCompany, HasFactory;

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productUnitConversion(): BelongsTo
    {
        return $this->belongsTo(ProductUnitConversion::class);
    }

    public function productSize(): BelongsTo
    {
        return $this->belongsTo(ProductSize::class);
    }

    public function sizeLabel(): ?string
    {
        return $this->productSize?->label() ?? $this->product?->sizeLabel();
    }

    public function productDisplayNameWithSize(): string
    {
        return trim(($this->product?->displayName() ?? '').($this->sizeLabel() ? ' - '.$this->sizeLabel() : ''));
    }

    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    public function sellingUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'selling_unit_id');
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'base_quantity' => 'decimal:4',
            'conversion_factor' => 'decimal:4',
            'conversion_factor_to_base' => 'decimal:4',
            'unit_cost' => 'decimal:2',
            'base_unit_cost' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'discount_per_unit' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'gross_total' => 'decimal:2',
            'net_unit_price' => 'decimal:2',
            'net_total' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }
}
