<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['company_id', 'customer_material_issue_id', 'customer_material_plan_line_id', 'product_id', 'transaction_unit_id', 'base_unit_id', 'product_name_snapshot', 'unit_name_snapshot', 'unit_code_snapshot', 'base_unit_name_snapshot', 'base_unit_code_snapshot', 'conversion_factor_snapshot', 'quantity', 'base_quantity', 'agreed_unit_price', 'line_value', 'base_unit_cost', 'line_cost'])]
class CustomerMaterialIssueLine extends Model
{
    use HasCompany;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Posted material issue lines are immutable.'));
        static::deleting(fn () => throw new LogicException('Posted material issue lines cannot be deleted.'));
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(CustomerMaterialIssue::class, 'customer_material_issue_id');
    }

    public function planLine(): BelongsTo
    {
        return $this->belongsTo(CustomerMaterialPlanLine::class, 'customer_material_plan_line_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return ['conversion_factor_snapshot' => 'decimal:12', 'quantity' => 'decimal:12', 'base_quantity' => 'decimal:12', 'agreed_unit_price' => 'decimal:2', 'line_value' => 'decimal:2', 'base_unit_cost' => 'decimal:4', 'line_cost' => 'decimal:2'];
    }
}
