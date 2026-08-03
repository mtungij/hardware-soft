<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'production_order_id', 'source_recipe_item_id', 'line_type',
    'material_product_id', 'name', 'unit_id', 'normalized_quantity_per_output',
    'planned_quantity', 'actual_quantity', 'unit_cost', 'planned_cost', 'actual_cost',
    'entry_mode', 'source_quantity', 'source_yield_quantity', 'notes', 'sort_order',
])]
class ProductionOrderMaterial extends Model
{
    use HasCompany;

    public const TYPE_INVENTORY = 'inventory';

    public const TYPE_NON_INVENTORY_QUANTITY = 'non_inventory_quantity';

    public const TYPE_NON_INVENTORY_COST = 'non_inventory_cost';

    protected function casts(): array
    {
        return [
            'normalized_quantity_per_output' => 'decimal:12', 'planned_quantity' => 'decimal:12',
            'actual_quantity' => 'decimal:12', 'unit_cost' => 'decimal:4',
            'planned_cost' => 'decimal:4', 'actual_cost' => 'decimal:4',
            'source_quantity' => 'decimal:12', 'source_yield_quantity' => 'decimal:12',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function sourceRecipeItem(): BelongsTo
    {
        return $this->belongsTo(ProductionRecipeItem::class, 'source_recipe_item_id');
    }

    public function materialProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'material_product_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
