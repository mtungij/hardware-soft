<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'production_order_id', 'source_recipe_id', 'recipe_name', 'recipe_code',
    'recipe_version', 'recipe_output_quantity', 'recipe_output_unit_id', 'captured_at',
])]
class ProductionOrderRecipeSnapshot extends Model
{
    use HasCompany;

    protected function casts(): array
    {
        return ['recipe_output_quantity' => 'decimal:12', 'captured_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function sourceRecipe(): BelongsTo
    {
        return $this->belongsTo(ProductionRecipe::class, 'source_recipe_id');
    }

    public function outputUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'recipe_output_unit_id');
    }
}
