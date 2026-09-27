<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'internal_sale_id', 'product_id', 'product_unit_conversion_id', 'transaction_unit_id', 'transaction_unit_name_snapshot', 'transaction_unit_code_snapshot', 'transaction_quantity', 'conversion_factor_snapshot', 'base_quantity', 'internal_unit_price', 'price_source', 'line_total', 'company_base_unit_cost', 'source_acquisition_base_unit_cost', 'destination_acquisition_base_unit_cost', 'notes'])]
class InternalSaleItem extends Model
{
    use HasCompany;

    public function internalSale(): BelongsTo
    {
        return $this->belongsTo(InternalSale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function transactionUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'transaction_unit_id');
    }

    protected function casts(): array
    {
        return [
            'transaction_quantity' => 'decimal:4',
            'conversion_factor_snapshot' => 'decimal:4',
            'base_quantity' => 'decimal:4',
            'internal_unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'company_base_unit_cost' => 'decimal:6',
            'source_acquisition_base_unit_cost' => 'decimal:6',
            'destination_acquisition_base_unit_cost' => 'decimal:6',
        ];
    }
}
