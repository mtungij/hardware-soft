<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'customer_material_account_id', 'product_id', 'product_unit_conversion_id', 'transaction_unit_id', 'base_unit_id', 'product_name_snapshot', 'unit_name_snapshot', 'unit_code_snapshot', 'base_unit_name_snapshot', 'base_unit_code_snapshot', 'conversion_factor_snapshot', 'planned_quantity', 'planned_base_quantity', 'agreed_unit_price', 'planned_line_total', 'revision', 'amendment_reason', 'created_by', 'updated_by'])]
class CustomerMaterialPlanLine extends Model
{
    use HasCompany;

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerMaterialAccount::class, 'customer_material_account_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function transactionUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'transaction_unit_id');
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function issueLines(): HasMany
    {
        return $this->hasMany(CustomerMaterialIssueLine::class);
    }

    public function issuedQuantity(): float
    {
        return (float) $this->issueLines()->sum('quantity');
    }

    public function remainingQuantity(): float
    {
        return max(0, round((float) $this->planned_quantity - $this->issuedQuantity(), 12));
    }

    protected function casts(): array
    {
        return ['conversion_factor_snapshot' => 'decimal:12', 'planned_quantity' => 'decimal:12', 'planned_base_quantity' => 'decimal:12', 'agreed_unit_price' => 'decimal:2', 'planned_line_total' => 'decimal:2', 'revision' => 'integer'];
    }
}
