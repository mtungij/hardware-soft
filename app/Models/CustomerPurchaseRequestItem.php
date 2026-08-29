<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'customer_purchase_request_id', 'product_id', 'base_unit_id', 'transaction_unit_id', 'product_unit_conversion_id', 'product_name_snapshot', 'sku_snapshot', 'base_unit_name_snapshot', 'transaction_unit_name_snapshot', 'transaction_quantity', 'conversion_factor_snapshot', 'base_quantity', 'display_unit_price_snapshot', 'customer_notes'])]
class CustomerPurchaseRequestItem extends Model
{
    use HasCompany;

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(CustomerPurchaseRequest::class, 'customer_purchase_request_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function conversion(): BelongsTo
    {
        return $this->belongsTo(ProductUnitConversion::class, 'product_unit_conversion_id');
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function transactionUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'transaction_unit_id');
    }

    protected function casts(): array
    {
        return [
            'transaction_quantity' => 'decimal:4', 'conversion_factor_snapshot' => 'decimal:4',
            'base_quantity' => 'decimal:4', 'display_unit_price_snapshot' => 'decimal:2',
        ];
    }
}
