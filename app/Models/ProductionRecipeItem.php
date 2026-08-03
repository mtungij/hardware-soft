<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'production_recipe_id', 'material_product_id', 'material_unit_id',
    'cost_type', 'cost_name', 'entry_mode', 'source_quantity', 'yield_quantity',
    'normalized_quantity', 'unit_cost', 'authoring_basis', 'authoring_quantity',
    'authoring_unit_cost', 'authoring_output_quantity', 'notes', 'sort_order',
])]
class ProductionRecipeItem extends Model
{
    use HasCompany, HasFactory;

    public const TYPE_INVENTORY = 'inventory';

    public const TYPE_NON_INVENTORY = 'non_inventory';

    public const TYPES = [self::TYPE_INVENTORY, self::TYPE_NON_INVENTORY];

    public const MODE_PER_OUTPUT = 'per_output';

    public const MODE_YIELD = 'yield';

    public const MODES = [self::MODE_PER_OUTPUT, self::MODE_YIELD];

    public const AUTHORING_PER_FINISHED_UNIT = 'per_finished_unit';

    public const AUTHORING_PER_RECIPE_OUTPUT = 'per_recipe_output';

    public const AUTHORING_BASES = [self::AUTHORING_PER_FINISHED_UNIT, self::AUTHORING_PER_RECIPE_OUTPUT];

    protected function casts(): array
    {
        return [
            'source_quantity' => 'decimal:12',
            'yield_quantity' => 'decimal:12',
            'normalized_quantity' => 'decimal:12',
            'unit_cost' => 'decimal:4',
            'authoring_quantity' => 'decimal:12',
            'authoring_unit_cost' => 'decimal:8',
            'authoring_output_quantity' => 'decimal:12',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(ProductionRecipe::class, 'production_recipe_id');
    }

    public function materialProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'material_product_id');
    }

    public function materialUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'material_unit_id');
    }
}
