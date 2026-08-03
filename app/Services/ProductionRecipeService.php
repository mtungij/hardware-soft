<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductionRecipe;
use App\Models\ProductionRecipeItem;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductionRecipeService
{
    public function __construct(private ProductionRecipeCalculator $calculator) {}

    /**
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $items
     */
    public function save(array $header, array $items, User $user, ?ProductionRecipe $recipe = null): ProductionRecipe
    {
        $companyId = (int) $user->company_id;
        abort_unless($companyId && (! $recipe || (int) $recipe->company_id === $companyId), 404);

        if ($recipe?->status === ProductionRecipe::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'status' => __('production.recipes.validation.active_read_only'),
            ]);
        }

        $validated = Validator::make($header, [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable', 'string', 'max:100',
                Rule::unique('production_recipes', 'code')
                    ->where(fn ($query) => $query->where('company_id', $companyId))
                    ->ignore($recipe?->id),
            ],
            'version' => ['nullable', 'string', 'max:50'],
            'product_id' => ['required', 'integer'],
            'output_quantity' => ['required', 'numeric', 'gt:0'],
            'output_unit_id' => ['required', 'integer'],
            'status' => ['required', Rule::in(ProductionRecipe::STATUSES)],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'output_quantity.gt' => __('production.recipes.validation.output_positive'),
            'effective_to.after_or_equal' => __('production.recipes.validation.effective_dates'),
        ])->validate();

        $finishedProduct = Product::query()
            ->where('company_id', $companyId)
            ->whereKey($validated['product_id'])
            ->first();

        if (! $finishedProduct || ! $finishedProduct->isManufactured()) {
            throw ValidationException::withMessages([
                'product_id' => __('production.recipes.validation.manufactured_only'),
            ]);
        }

        $outputUnit = Unit::query()
            ->where('company_id', $companyId)
            ->whereKey($validated['output_unit_id'])
            ->first();

        if (! $outputUnit || ! $this->unitCompatible($finishedProduct, $outputUnit)) {
            throw ValidationException::withMessages([
                'output_unit_id' => __('production.recipes.validation.invalid_unit'),
            ]);
        }

        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => __('production.recipes.validation.items_required'),
            ]);
        }

        $normalizedItems = $this->validateItems($items, $finishedProduct, $companyId, (string) $validated['output_quantity']);
        $targetStatus = $validated['status'];

        return DB::transaction(function () use ($validated, $normalizedItems, $targetStatus, $recipe, $companyId, $user): ProductionRecipe {
            $values = [
                ...$validated,
                'company_id' => $companyId,
                'status' => $targetStatus === ProductionRecipe::STATUS_ACTIVE
                    ? ProductionRecipe::STATUS_DRAFT
                    : $targetStatus,
                'code' => filled($validated['code'] ?? null) ? $validated['code'] : null,
                'version' => filled($validated['version'] ?? null) ? $validated['version'] : null,
                'effective_from' => filled($validated['effective_from'] ?? null) ? $validated['effective_from'] : null,
                'effective_to' => filled($validated['effective_to'] ?? null) ? $validated['effective_to'] : null,
                'updated_by' => $user->id,
            ];

            if ($recipe) {
                $recipe->update($values);
                $recipe->items()->delete();
            } else {
                $recipe = ProductionRecipe::query()->create([
                    ...$values,
                    'created_by' => $user->id,
                ]);
            }

            foreach ($normalizedItems as $index => $item) {
                $recipe->items()->create([
                    ...$item,
                    'company_id' => $companyId,
                    'sort_order' => $index,
                ]);
            }

            if ($targetStatus === ProductionRecipe::STATUS_ACTIVE) {
                $this->activate($recipe, $user);
            }

            return $recipe->refresh()->load(['product', 'outputUnit', 'items.materialProduct', 'items.materialUnit']);
        });
    }

    public function activate(ProductionRecipe $recipe, User $user): ProductionRecipe
    {
        abort_unless((int) $recipe->company_id === (int) $user->company_id, 404);

        return DB::transaction(function () use ($recipe, $user): ProductionRecipe {
            ProductionRecipe::query()
                ->forCurrentCompany()
                ->where('product_id', $recipe->product_id)
                ->lockForUpdate()
                ->get();

            ProductionRecipe::query()
                ->forCurrentCompany()
                ->where('product_id', $recipe->product_id)
                ->whereKeyNot($recipe->id)
                ->where('status', ProductionRecipe::STATUS_ACTIVE)
                ->get()
                ->each(fn (ProductionRecipe $active) => $active->update([
                    'status' => ProductionRecipe::STATUS_INACTIVE,
                    'updated_by' => $user->id,
                ]));

            $recipe->update([
                'status' => ProductionRecipe::STATUS_ACTIVE,
                'updated_by' => $user->id,
            ]);

            return $recipe->refresh();
        });
    }

    public function deactivate(ProductionRecipe $recipe, User $user): ProductionRecipe
    {
        abort_unless((int) $recipe->company_id === (int) $user->company_id, 404);
        $recipe->update(['status' => ProductionRecipe::STATUS_INACTIVE, 'updated_by' => $user->id]);

        return $recipe->refresh();
    }

    public function duplicate(ProductionRecipe $recipe, User $user): ProductionRecipe
    {
        abort_unless((int) $recipe->company_id === (int) $user->company_id, 404);

        return DB::transaction(function () use ($recipe, $user): ProductionRecipe {
            $recipe->loadMissing('items');
            $copy = $recipe->replicate([
                'active_product_id', 'status', 'code', 'effective_from', 'effective_to',
                'created_by', 'updated_by',
            ]);
            $copy->name = $recipe->name.' (Copy)';
            $copy->code = null;
            $copy->version = is_numeric($recipe->version)
                ? (string) ((int) $recipe->version + 1)
                : trim(($recipe->version ?: '1').' copy');
            $copy->status = ProductionRecipe::STATUS_DRAFT;
            $copy->effective_from = null;
            $copy->effective_to = null;
            $copy->created_by = $user->id;
            $copy->updated_by = $user->id;
            $copy->save();

            foreach ($recipe->items as $item) {
                $copy->items()->create([
                    ...$item->only([
                        'company_id', 'material_product_id', 'material_unit_id', 'cost_type',
                        'cost_name', 'entry_mode', 'source_quantity', 'yield_quantity',
                        'normalized_quantity', 'unit_cost', 'notes', 'sort_order',
                        'authoring_basis', 'authoring_quantity', 'authoring_unit_cost',
                        'authoring_output_quantity',
                    ]),
                ]);
            }

            return $copy->load(['product', 'outputUnit', 'items.materialProduct', 'items.materialUnit']);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function validateItems(array $items, Product $finishedProduct, int $companyId, string $recipeOutputQuantity): array
    {
        $normalized = [];
        $inventoryProducts = [];
        $costNames = [];

        foreach (array_values($items) as $index => $item) {
            $field = "items.{$index}";
            $type = $item['cost_type'] ?? null;

            if (! in_array($type, ProductionRecipeItem::TYPES, true)) {
                throw ValidationException::withMessages(["{$field}.cost_type" => __('production.recipes.validation.invalid_type')]);
            }

            $mode = $type === ProductionRecipeItem::TYPE_NON_INVENTORY
                ? ProductionRecipeItem::MODE_PER_OUTPUT
                : ($item['entry_mode'] ?? ProductionRecipeItem::MODE_PER_OUTPUT);

            if (! in_array($mode, ProductionRecipeItem::MODES, true)) {
                throw ValidationException::withMessages(["{$field}.entry_mode" => __('production.recipes.validation.invalid_mode')]);
            }

            $authoringBasis = filled($item['authoring_basis'] ?? null) ? (string) $item['authoring_basis'] : null;
            if ($authoringBasis !== null && ! in_array($authoringBasis, ProductionRecipeItem::AUTHORING_BASES, true)) {
                throw ValidationException::withMessages(["{$field}.authoring_basis" => __('production.recipes.validation.invalid_basis')]);
            }

            $authoringQuantity = null;
            $authoringUnitCost = null;
            $authoringOutputQuantity = null;
            if ($authoringBasis !== null) {
                if (filled($item['authoring_quantity'] ?? null)) {
                    try {
                        $authoringQuantity = $this->calculator->decimal($item['authoring_quantity']);
                        if (bccomp($authoringQuantity, '0', ProductionRecipeCalculator::QUANTITY_SCALE) <= 0
                            || ! $this->decimalFitsStorage($authoringQuantity, 12, 12)) {
                            throw new \InvalidArgumentException;
                        }
                    } catch (\InvalidArgumentException) {
                        throw ValidationException::withMessages(["{$field}.source_quantity" => __('production.recipes.validation.invalid_authoring_metadata')]);
                    }
                }

                if (filled($item['authoring_unit_cost'] ?? null)) {
                    try {
                        $authoringUnitCost = $this->calculator->decimal($item['authoring_unit_cost']);
                        if (bccomp($authoringUnitCost, '0', 8) < 0
                            || ! $this->decimalFitsStorage($authoringUnitCost, 16, 8)) {
                            throw new \InvalidArgumentException;
                        }
                    } catch (\InvalidArgumentException) {
                        throw ValidationException::withMessages(["{$field}.unit_cost" => __('production.recipes.validation.invalid_authoring_metadata')]);
                    }
                }

                try {
                    $authoringOutputQuantity = $this->calculator->decimal($item['authoring_output_quantity'] ?? $recipeOutputQuantity);
                    if (bccomp($authoringOutputQuantity, '0', ProductionRecipeCalculator::QUANTITY_SCALE) <= 0
                        || ! $this->decimalFitsStorage($authoringOutputQuantity, 12, 12)) {
                        throw new \InvalidArgumentException;
                    }
                } catch (\InvalidArgumentException) {
                    throw ValidationException::withMessages(['output_quantity' => __('production.recipes.validation.invalid_authoring_metadata')]);
                }
            }

            $values = [
                'material_product_id' => null,
                'material_unit_id' => null,
                'cost_type' => $type,
                'cost_name' => null,
                'entry_mode' => $mode,
                'source_quantity' => null,
                'yield_quantity' => null,
                'normalized_quantity' => null,
                'unit_cost' => null,
                'authoring_basis' => $authoringBasis,
                'authoring_quantity' => $authoringQuantity,
                'authoring_unit_cost' => $authoringUnitCost,
                'authoring_output_quantity' => $authoringOutputQuantity,
                'notes' => filled($item['notes'] ?? null) ? $item['notes'] : null,
            ];

            if ($type === ProductionRecipeItem::TYPE_INVENTORY) {
                $material = Product::query()
                    ->where('company_id', $companyId)
                    ->whereKey($item['material_product_id'] ?? null)
                    ->first();
                $unit = Unit::query()
                    ->where('company_id', $companyId)
                    ->whereKey($item['material_unit_id'] ?? null)
                    ->first();

                if (! $material || (int) $material->id === (int) $finishedProduct->id) {
                    throw ValidationException::withMessages(["{$field}.material_product_id" => __('production.recipes.validation.invalid_material')]);
                }

                if (! $unit || ! $this->unitCompatible($material, $unit)) {
                    throw ValidationException::withMessages(["{$field}.material_unit_id" => __('production.recipes.validation.incompatible_unit')]);
                }

                if (in_array($material->id, $inventoryProducts, true)) {
                    throw ValidationException::withMessages(["{$field}.material_product_id" => __('production.recipes.validation.duplicate_material')]);
                }
                $inventoryProducts[] = $material->id;

                try {
                    $source = $this->calculator->decimal($item['source_quantity'] ?? null);
                    $requirement = $mode === ProductionRecipeItem::MODE_YIELD
                        ? $this->calculator->normalizeYield($source, $item['yield_quantity'] ?? null)
                        : $this->calculator->normalizePerOutput($source);
                } catch (\InvalidArgumentException) {
                    throw ValidationException::withMessages([
                        $mode === ProductionRecipeItem::MODE_YIELD ? "{$field}.yield_quantity" : "{$field}.source_quantity" => $mode === ProductionRecipeItem::MODE_YIELD
                                ? __('production.recipes.validation.positive_yield')
                                : __('production.recipes.validation.positive_quantity'),
                    ]);
                }

                $values = [
                    ...$values,
                    'material_product_id' => $material->id,
                    'material_unit_id' => $unit->id,
                    'source_quantity' => $source,
                    'yield_quantity' => $mode === ProductionRecipeItem::MODE_YIELD
                        ? $this->calculator->decimal($item['yield_quantity'])
                        : null,
                    'normalized_quantity' => $requirement,
                ];
            } else {
                $name = trim((string) ($item['cost_name'] ?? ''));
                $nameKey = mb_strtolower($name);

                if ($name === '') {
                    throw ValidationException::withMessages(["{$field}.cost_name" => __('production.recipes.validation.cost_name_required')]);
                }
                if (in_array($nameKey, $costNames, true)) {
                    throw ValidationException::withMessages(["{$field}.cost_name" => __('production.recipes.validation.duplicate_cost')]);
                }
                $costNames[] = $nameKey;

                $hasQuantity = filled($item['source_quantity'] ?? null);
                $hasCost = filled($item['unit_cost'] ?? null);

                if (! $hasQuantity && ! $hasCost) {
                    throw ValidationException::withMessages(["{$field}.source_quantity" => __('production.recipes.validation.quantity_or_cost')]);
                }

                $unit = null;
                $quantity = null;
                if ($hasQuantity) {
                    try {
                        $quantity = $this->calculator->normalizePerOutput($item['source_quantity']);
                    } catch (\InvalidArgumentException) {
                        throw ValidationException::withMessages(["{$field}.source_quantity" => __('production.recipes.validation.positive_quantity')]);
                    }
                    $unit = Unit::query()->where('company_id', $companyId)->whereKey($item['material_unit_id'] ?? null)->first();
                    if (! $unit) {
                        throw ValidationException::withMessages(["{$field}.material_unit_id" => __('production.recipes.validation.invalid_unit')]);
                    }
                }

                $unitCost = null;
                if ($hasCost) {
                    try {
                        $unitCost = $this->calculator->decimal($item['unit_cost']);
                    } catch (\InvalidArgumentException) {
                        throw ValidationException::withMessages(["{$field}.unit_cost" => __('production.recipes.validation.non_negative_cost')]);
                    }
                    if (bccomp($unitCost, '0', ProductionRecipeCalculator::COST_SCALE) < 0) {
                        throw ValidationException::withMessages(["{$field}.unit_cost" => __('production.recipes.validation.non_negative_cost')]);
                    }
                }

                $values = [
                    ...$values,
                    'material_unit_id' => $unit?->id,
                    'cost_name' => $name,
                    'source_quantity' => $quantity,
                    'normalized_quantity' => $quantity,
                    'unit_cost' => $unitCost,
                ];
            }

            $normalized[] = $values;
        }

        return $normalized;
    }

    private function unitCompatible(Product $material, Unit $unit): bool
    {
        if (in_array($unit->id, array_filter([$material->unit_id, $material->purchase_unit_id]), true)) {
            return true;
        }

        return $unit->measurement_type_id
            && (int) $unit->measurement_type_id === (int) $material->measurement_type_id;
    }

    private function decimalFitsStorage(string $value, int $wholeDigits, int $decimalPlaces): bool
    {
        if (! preg_match('/^[+\-]?(\d+)(?:\.(\d+))?$/', $value, $matches)) {
            return false;
        }

        return strlen(ltrim($matches[1], '0')) <= $wholeDigits
            && strlen($matches[2] ?? '') <= $decimalPlaces;
    }
}
