<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'quotation_id', 'product_id', 'base_unit_id', 'transaction_unit_id', 'product_unit_conversion_id', 'product_name_snapshot', 'sku_snapshot', 'base_unit_name_snapshot', 'transaction_unit_name_snapshot', 'transaction_quantity', 'conversion_factor_snapshot', 'base_quantity', 'unit_price', 'discount_per_unit', 'discount_amount', 'tax_amount', 'line_total'])]
class QuotationItem extends Model
{
    use HasCompany;

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function conversion(): BelongsTo
    {
        return $this->belongsTo(ProductUnitConversion::class, 'product_unit_conversion_id');
    }

    protected function casts(): array
    {
        return [
            'transaction_quantity' => 'decimal:4', 'conversion_factor_snapshot' => 'decimal:4',
            'base_quantity' => 'decimal:4', 'unit_price' => 'decimal:2',
            'discount_per_unit' => 'decimal:2', 'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2', 'line_total' => 'decimal:2',
        ];
    }
}
