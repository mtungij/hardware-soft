<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'company_id', 'production_order_costing_id', 'production_order_material_id',
    'line_type', 'cost_basis', 'name', 'product_id', 'unit_id', 'planned_quantity',
    'actual_quantity', 'planned_unit_cost', 'actual_unit_cost', 'planned_total_cost',
    'actual_total_cost', 'quantity_variance', 'cost_variance', 'source_type',
    'source_id', 'is_manual', 'notes',
])]
class ProductionOrderCostingLine extends Model
{
    use HasCompany;

    public const INVENTORY = 'inventory_material';

    public const NON_INVENTORY = 'other_non_inventory';

    public const ADJUSTMENT = 'adjustment';

    protected static function booted(): void
    {
        static::updating(function (ProductionOrderCostingLine $line): void {
            if ($line->costing?->status === ProductionOrderCosting::STATUS_FINALIZED) {
                throw new LogicException('Finalized costing lines are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'planned_quantity' => 'decimal:12', 'actual_quantity' => 'decimal:12',
            'planned_unit_cost' => 'decimal:8', 'actual_unit_cost' => 'decimal:8',
            'planned_total_cost' => 'decimal:4', 'actual_total_cost' => 'decimal:4',
            'quantity_variance' => 'decimal:12', 'cost_variance' => 'decimal:4',
            'is_manual' => 'boolean',
        ];
    }

    public function costing(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderCosting::class, 'production_order_costing_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderMaterial::class, 'production_order_material_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
