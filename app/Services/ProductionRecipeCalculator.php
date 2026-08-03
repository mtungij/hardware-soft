<?php

namespace App\Services;

use App\Models\ProductionRecipe;
use App\Models\ProductionRecipeItem;
use InvalidArgumentException;

class ProductionRecipeCalculator
{
    public const QUANTITY_SCALE = 12;

    public const COST_SCALE = 4;

    public function normalizeYield(string|int|float $sourceQuantity, string|int|float $yieldQuantity): string
    {
        $source = $this->decimal($sourceQuantity);
        $yield = $this->decimal($yieldQuantity);

        if (bccomp($source, '0', self::QUANTITY_SCALE) <= 0 || bccomp($yield, '0', self::QUANTITY_SCALE) <= 0) {
            throw new InvalidArgumentException('Source and yield quantities must be greater than zero.');
        }

        return bcdiv($source, $yield, self::QUANTITY_SCALE);
    }

    public function normalizePerOutput(string|int|float $quantity): string
    {
        $normalized = $this->decimal($quantity);

        if (bccomp($normalized, '0', self::QUANTITY_SCALE) <= 0) {
            throw new InvalidArgumentException('Required quantity must be greater than zero.');
        }

        return bcadd($normalized, '0', self::QUANTITY_SCALE);
    }

    /**
     * @return array{target_output:string, materials:array<int, array<string, mixed>>, non_inventory_costs:array<int, array<string, mixed>>, direct_non_inventory_cost:string}
     */
    public function calculate(ProductionRecipe $recipe, string|int|float $targetOutput): array
    {
        $target = $this->decimal($targetOutput);

        if (bccomp($target, '0', self::QUANTITY_SCALE) <= 0) {
            throw new InvalidArgumentException('Target output must be greater than zero.');
        }

        $recipe->loadMissing(['items.materialProduct', 'items.materialUnit']);
        $materials = [];
        $costs = [];
        $directCost = '0.0000';

        foreach ($recipe->items as $item) {
            $required = $item->normalized_quantity !== null
                ? bcmul((string) $item->normalized_quantity, $target, self::QUANTITY_SCALE)
                : null;

            if ($item->cost_type === ProductionRecipeItem::TYPE_INVENTORY) {
                $materials[] = [
                    'item_id' => $item->id,
                    'name' => $item->materialProduct?->name,
                    'unit' => $item->materialUnit?->short_name,
                    'normalized_quantity' => (string) $item->normalized_quantity,
                    'required_quantity' => $required,
                ];

                continue;
            }

            $totalCost = $item->unit_cost !== null
                ? bcmul((string) $item->unit_cost, $target, self::COST_SCALE)
                : '0.0000';
            $directCost = bcadd($directCost, $totalCost, self::COST_SCALE);
            $costs[] = [
                'item_id' => $item->id,
                'name' => $item->cost_name,
                'unit' => $item->materialUnit?->short_name,
                'normalized_quantity' => $item->normalized_quantity !== null ? (string) $item->normalized_quantity : null,
                'required_quantity' => $required,
                'unit_cost' => $item->unit_cost !== null ? (string) $item->unit_cost : null,
                'total_cost' => $totalCost,
            ];
        }

        return [
            'target_output' => bcadd($target, '0', self::QUANTITY_SCALE),
            'materials' => $materials,
            'non_inventory_costs' => $costs,
            'direct_non_inventory_cost' => $directCost,
        ];
    }

    public function decimal(string|int|float|null $value): string
    {
        $normalized = str_replace([',', ' ', "\u{00A0}"], '', trim((string) $value));

        if ($normalized === '' || ! is_numeric($normalized)) {
            throw new InvalidArgumentException('A valid decimal value is required.');
        }

        return $normalized;
    }
}
