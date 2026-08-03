<?php

use App\Models\ProductionRecipe;
use App\Models\ProductionRecipeItem;
use App\Models\Product;
use App\Models\Unit;
use App\Services\ProductionRecipeCalculator;
use App\Services\ProductionRecipeService;
use App\Support\CompanyFeatures;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException as InvalidDecimalArgument;

use function Livewire\Volt\hydrate;
use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'recipe' => null,
    'name' => '',
    'code' => '',
    'version' => '1',
    'product_id' => '',
    'output_quantity' => '1',
    'output_unit_id' => '',
    'status' => ProductionRecipe::STATUS_DRAFT,
    'effective_from' => '',
    'effective_to' => '',
    'notes' => '',
    'items' => [],
]);

$newItem = fn (string $type): array => [
    'uuid' => (string) Str::uuid(),
    'cost_type' => $type,
    'material_product_id' => '',
    'cost_name' => '',
    'entry_mode' => ProductionRecipeItem::MODE_PER_OUTPUT,
    'source_quantity' => '',
    'yield_quantity' => '',
    'material_unit_id' => '',
    'unit_cost' => '',
    'notes' => '',
    'basis' => 'recipe_output',
    'legacy_authoring' => false,
];

$repairItemUiState = function (): void {
    $this->items = collect($this->items)
        ->values()
        ->map(function ($item): array {
            $item = is_array($item) ? $item : [];
            $type = (string) ($item['cost_type'] ?? ProductionRecipeItem::TYPE_INVENTORY);
            $defaults = $this->newItem($type);

            return [
                ...$defaults,
                ...$item,
                'uuid' => filled($item['uuid'] ?? null) ? (string) $item['uuid'] : $defaults['uuid'],
            ];
        })
        ->all();
};

hydrate(fn () => $this->repairItemUiState());

$stockUnitIdForMaterial = function ($productId): string {
    if (! filled($productId)) {
        return '';
    }

    $product = Product::query()
        ->where('company_id', CompanyFeatures::companyId())
        ->where('status', 'active')
        ->whereKey($productId)
        ->first(['id', 'unit_id', 'purchase_unit_id']);
    $unitId = $product?->unit_id ?: $product?->purchase_unit_id;

    if (! $unitId || ! Unit::query()->where('company_id', CompanyFeatures::companyId())->whereKey($unitId)->exists()) {
        return '';
    }

    return (string) $unitId;
};

$materialHasMissingStockUnit = function ($productId): bool {
    $product = Product::query()
        ->where('company_id', CompanyFeatures::companyId())
        ->whereKey($productId)
        ->first(['id', 'unit_id', 'purchase_unit_id']);

    if (! $product) {
        return false;
    }

    $unitId = $product->unit_id ?: $product->purchase_unit_id;

    return ! $unitId || ! Unit::query()->where('company_id', CompanyFeatures::companyId())->whereKey($unitId)->exists();
};

$updatedItems = function ($value = null, ?string $key = null): void {
    $this->repairItemUiState();

    if (! $key || (! str_ends_with($key, '.material_product_id') && ! str_ends_with($key, '.material_unit_id'))) {
        return;
    }

    $index = (int) Str::before($key, '.');
    if (($this->items[$index]['cost_type'] ?? null) !== ProductionRecipeItem::TYPE_INVENTORY) {
        return;
    }

    $this->items[$index]['material_unit_id'] = $this->stockUnitIdForMaterial($this->items[$index]['material_product_id'] ?? null);
    $this->resetValidation("items.{$index}.material_unit_id");
};

mount(function (?ProductionRecipe $recipe = null): void {
    abort_unless(
        CompanyFeatures::manufacturingEnabled()
        && (auth()->user()?->can('production.view_recipes') || auth()->user()?->can('production.manage_recipes')),
        403
    );
    abort_unless(auth()->user()?->can('production.manage_recipes'), 403);

    if (! $recipe) {
        $this->items = [$this->newItem(ProductionRecipeItem::TYPE_INVENTORY)];

        return;
    }

    abort_unless((int) $recipe->company_id === (int) CompanyFeatures::companyId(), 404);
    abort_if($recipe->status === ProductionRecipe::STATUS_ACTIVE, 409, __('production.recipes.validation.active_read_only'));
    $recipe->load('items');
    $this->recipe = $recipe;
    $this->name = $recipe->name;
    $this->code = $recipe->code ?? '';
    $this->version = $recipe->version ?? '';
    $this->product_id = (string) $recipe->product_id;
    $this->output_quantity = (string) $recipe->output_quantity;
    $this->output_unit_id = (string) $recipe->output_unit_id;
    $this->status = $recipe->status;
    $this->effective_from = $recipe->effective_from?->toDateString() ?? '';
    $this->effective_to = $recipe->effective_to?->toDateString() ?? '';
    $this->notes = $recipe->notes ?? '';
    $this->items = $recipe->items->map(function (ProductionRecipeItem $item): array {
        $hasAuthoringMetadata = in_array($item->authoring_basis, ProductionRecipeItem::AUTHORING_BASES, true);

        return [
            'uuid' => (string) Str::uuid(),
            'cost_type' => $item->cost_type,
            'material_product_id' => $item->material_product_id ? (string) $item->material_product_id : '',
            'cost_name' => $item->cost_name ?? '',
            'entry_mode' => ProductionRecipeItem::MODE_PER_OUTPUT,
            'source_quantity' => $hasAuthoringMetadata && $item->authoring_quantity !== null
                ? $item->authoring_quantity
                : ($item->normalized_quantity ?? ''),
            'yield_quantity' => '',
            'material_unit_id' => $item->material_unit_id ? (string) $item->material_unit_id : '',
            'unit_cost' => $hasAuthoringMetadata && $item->authoring_unit_cost !== null
                ? $item->authoring_unit_cost
                : ($item->unit_cost ?? ''),
            'notes' => $item->notes ?? '',
            'basis' => $item->authoring_basis === ProductionRecipeItem::AUTHORING_PER_RECIPE_OUTPUT
                ? 'recipe_output'
                : 'finished_unit',
            'legacy_authoring' => ! $hasAuthoringMetadata,
        ];
    })->all();
});

$addInventoryMaterial = fn () => $this->items[] = $this->newItem(ProductionRecipeItem::TYPE_INVENTORY);
$addNonInventoryCost = fn () => $this->items[] = $this->newItem(ProductionRecipeItem::TYPE_NON_INVENTORY);

$removeItem = function (string $uuid): void {
    $this->items = collect($this->items)
        ->reject(fn (array $item) => ($item['uuid'] ?? null) === $uuid)
        ->values()
        ->all();
    $this->resetValidation();
};

$recipeOutputDecimal = function (): ?string {
    try {
        $output = app(ProductionRecipeCalculator::class)->decimal($this->output_quantity);

        return bccomp($output, '0', ProductionRecipeCalculator::QUANTITY_SCALE) > 0 ? $output : null;
    } catch (InvalidDecimalArgument) {
        return null;
    }
};

$formatAuthoringDecimal = function ($value): string {
    if ($value === null || $value === '') {
        return '—';
    }

    $formatted = (string) $value;
    if (str_contains($formatted, '.')) {
        $formatted = rtrim(rtrim($formatted, '0'), '.');
    }

    return $formatted === '' ? '0' : $formatted;
};

$formatAuthoringMoney = function ($value): string {
    $decimal = $this->formatAuthoringDecimal($value);
    if ($decimal === '—') {
        return $decimal;
    }

    [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
    $sign = str_starts_with($whole, '-') ? '-' : '';
    $whole = ltrim($whole, '-');
    $grouped = strrev(implode(',', str_split(strrev($whole === '' ? '0' : $whole), 3)));

    return $sign.$grouped.($fraction !== '' ? '.'.$fraction : '');
};

$isOneAuthoringDecimal = function ($value): bool {
    try {
        return bccomp(app(ProductionRecipeCalculator::class)->decimal($value), '1', ProductionRecipeCalculator::QUANTITY_SCALE) === 0;
    } catch (InvalidDecimalArgument) {
        return false;
    }
};

$decimalFitsStorage = function ($value, int $wholeDigits, int $decimalPlaces): bool {
    $normalized = str_replace([',', ' ', "\u{00A0}"], '', trim((string) $value));
    if (! preg_match('/^[+\-]?(\d+)(?:\.(\d+))?$/', $normalized, $matches)) {
        return true; // Existing numeric validation supplies the appropriate error.
    }

    $whole = ltrim($matches[1], '0');
    $fraction = $matches[2] ?? '';

    return strlen($whole) <= $wholeDigits && strlen($fraction) <= $decimalPlaces;
};

$basisEquivalence = function (array $item): array {
    $output = $this->recipeOutputDecimal();
    if ($output === null) {
        return ['quantity' => null, 'cost' => null];
    }

    $perRecipe = ($item['basis'] ?? 'recipe_output') === 'recipe_output';
    $calculator = app(ProductionRecipeCalculator::class);
    $quantity = null;
    $cost = null;

    try {
        if (filled($item['source_quantity'] ?? null)) {
            $source = $calculator->decimal($item['source_quantity']);
            if (bccomp($source, '0', ProductionRecipeCalculator::QUANTITY_SCALE) > 0) {
                $quantity = $perRecipe
                    ? bcdiv($source, $output, ProductionRecipeCalculator::QUANTITY_SCALE)
                    : bcmul($source, $output, ProductionRecipeCalculator::QUANTITY_SCALE);
            }
        }
    } catch (InvalidDecimalArgument) {
        $quantity = null;
    }

    try {
        if (filled($item['unit_cost'] ?? null)) {
            $enteredCost = $calculator->decimal($item['unit_cost']);
            if (bccomp($enteredCost, '0', ProductionRecipeCalculator::COST_SCALE) >= 0) {
                $cost = $perRecipe
                    ? bcdiv($enteredCost, $output, ProductionRecipeCalculator::COST_SCALE)
                    : bcmul($enteredCost, $output, ProductionRecipeCalculator::COST_SCALE);
            }
        }
    } catch (InvalidDecimalArgument) {
        $cost = null;
    }

    return ['quantity' => $quantity, 'cost' => $cost];
};

$itemsForPersistence = function (): array {
    $output = $this->recipeOutputDecimal();
    $calculator = app(ProductionRecipeCalculator::class);
    $stockUnitIds = Product::query()
        ->where('company_id', CompanyFeatures::companyId())
        ->whereIn('id', collect($this->items)->pluck('material_product_id')->filter()->all())
        ->get(['id', 'unit_id', 'purchase_unit_id'])
        ->mapWithKeys(fn (Product $product): array => [
            (string) $product->id => (string) ($product->unit_id ?: $product->purchase_unit_id ?: ''),
        ]);

    return collect($this->items)->map(function (array $item) use ($output, $calculator, $stockUnitIds): array {
        $persisted = $item;
        $perRecipe = ($item['basis'] ?? 'recipe_output') === 'recipe_output';
        $persisted['authoring_basis'] = $perRecipe
            ? ProductionRecipeItem::AUTHORING_PER_RECIPE_OUTPUT
            : ProductionRecipeItem::AUTHORING_PER_FINISHED_UNIT;
        $persisted['authoring_quantity'] = filled($item['source_quantity'] ?? null)
            ? (string) $item['source_quantity']
            : null;
        $persisted['authoring_unit_cost'] = filled($item['unit_cost'] ?? null)
            ? (string) $item['unit_cost']
            : null;
        $persisted['authoring_output_quantity'] = (string) $this->output_quantity;

        if (($item['cost_type'] ?? null) === ProductionRecipeItem::TYPE_INVENTORY) {
            $persisted['material_unit_id'] = $stockUnitIds->get((string) ($item['material_product_id'] ?? ''), '');
        }

        if ($perRecipe && $output !== null) {
            try {
                if (filled($item['source_quantity'] ?? null)) {
                    $persisted['source_quantity'] = $calculator->normalizeYield($item['source_quantity'], $output);
                }
            } catch (InvalidDecimalArgument) {
                // Let the existing recipe service return its normal field validation error.
            }

            try {
                if (filled($item['unit_cost'] ?? null)) {
                    $cost = $calculator->decimal($item['unit_cost']);
                    if (bccomp($cost, '0', ProductionRecipeCalculator::COST_SCALE) >= 0) {
                        $persisted['unit_cost'] = bcdiv($cost, $output, ProductionRecipeCalculator::COST_SCALE);
                    }
                }
            } catch (InvalidDecimalArgument) {
                // Let the existing recipe service return its normal field validation error.
            }
        }

        $persisted['entry_mode'] = ProductionRecipeItem::MODE_PER_OUTPUT;
        $persisted['yield_quantity'] = '';
        unset($persisted['basis'], $persisted['uuid'], $persisted['legacy_authoring']);

        return $persisted;
    })->all();
};

$save = function () {
    $this->repairItemUiState();

    $authoringErrors = [];
    if (filled($this->output_quantity) && ! $this->decimalFitsStorage($this->output_quantity, 10, 8)) {
        $authoringErrors['output_quantity'] = __('production.recipes.validation.output_precision');
    }

    foreach ($this->items as $index => $item) {
        if (! in_array($item['basis'] ?? null, ['finished_unit', 'recipe_output'], true)) {
            $authoringErrors["items.{$index}.basis"] = __('production.recipes.validation.invalid_basis');
        }

        if (($item['cost_type'] ?? null) === ProductionRecipeItem::TYPE_INVENTORY
            && filled($item['material_product_id'] ?? null)
            && $this->materialHasMissingStockUnit($item['material_product_id'])) {
            $authoringErrors["items.{$index}.material_unit_id"] = __('production.recipes.validation.stock_unit_missing');
        }

        foreach (['source_quantity', 'yield_quantity'] as $quantityField) {
            if (filled($item[$quantityField] ?? null) && ! $this->decimalFitsStorage($item[$quantityField], 12, 12)) {
                $authoringErrors["items.{$index}.{$quantityField}"] = __('production.recipes.validation.quantity_precision');
            }
        }
    }

    if ($authoringErrors !== []) {
        throw ValidationException::withMessages($authoringErrors);
    }

    $items = $this->itemsForPersistence();

    $recipe = app(ProductionRecipeService::class)->save([
        'name' => $this->name,
        'code' => $this->code,
        'version' => $this->version,
        'product_id' => $this->product_id,
        'output_quantity' => $this->output_quantity,
        'output_unit_id' => $this->output_unit_id,
        'status' => $this->status,
        'effective_from' => $this->effective_from,
        'effective_to' => $this->effective_to,
        'notes' => $this->notes,
    ], $items, auth()->user(), $this->recipe);

    session()->flash('success', 'Recipe saved successfully.');

    return $this->redirectRoute('production.recipes.show', $recipe, navigate: true);
};

?>

<div>
    <x-page-header
        :title="$recipe ? 'Edit Recipe' : 'Create Recipe'"
        description="Define normalized material requirements and direct non-inventory inputs without moving stock."
        :breadcrumbs="['Dashboard' => route('dashboard'), __('production.title') => route('production.index'), __('production.recipes.title') => route('production.recipes.index'), $recipe ? 'Edit' : 'Create' => null]"
    />

    @php
        $recipeUnits = Unit::query()
            ->where('company_id', CompanyFeatures::companyId())
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
        $recipeProducts = Product::query()
            ->where('company_id', CompanyFeatures::companyId())
            ->where('status', 'active')
            ->with('unit:id,name,short_name')
            ->orderBy('name')
            ->get();
        $manufacturedProducts = $recipeProducts->filter->isManufactured();
        $materialProducts = $recipeProducts->when($product_id, fn ($products) => $products->where('id', '!=', (int) $product_id));
        $materialProductsById = $recipeProducts->keyBy('id');
        $selectedFinishedProduct = $recipeProducts->firstWhere('id', (int) $product_id);
        $finishedProductLabel = $selectedFinishedProduct?->name ?: __('production.recipes.form.finished_product');
        $selectedOutputUnit = $recipeUnits->firstWhere('id', (int) $output_unit_id);
        $outputUnitLabel = $selectedOutputUnit?->short_name ?: __('production.recipes.form.finished_units');
        $headerSummaryKey = 'production.recipes.form.one_recipe_produces_'
            .($this->isOneAuthoringDecimal($output_quantity) ? 'singular' : 'plural')
            .($selectedFinishedProduct ? '' : '_without_product');
    @endphp

    <form wire:submit="save" class="space-y-6">
        <x-card title="Recipe Header">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <x-form-input label="Recipe Name" name="name" wire:model.blur="name" required />
                <x-form-input label="Recipe Code" name="code" wire:model.blur="code" />
                <x-form-input label="Version" name="version" wire:model.blur="version" />
                <label class="block text-sm font-bold">Manufactured Product
                    <select wire:model.live="product_id" class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950">
                        <option value="">Select product</option>
                        @foreach ($manufacturedProducts as $product)
                            <option value="{{ $product->id }}">{{ $product->name }}</option>
                        @endforeach
                    </select>
                    @error('product_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <x-form-input label="Output Quantity" name="output_quantity" type="number" min="0.00000001" step="0.00000001" wire:model.live.debounce.300ms="output_quantity" required />
                <label class="block text-sm font-bold">Output Unit
                    <select wire:model.live="output_unit_id" class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950">
                        <option value="">{{ __('production.recipes.form.select_unit') }}</option>
                        @foreach ($recipeUnits as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->short_name }})</option>
                        @endforeach
                    </select>
                    @error('output_unit_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm font-bold">Status
                    <select wire:model="status" class="mt-1 block w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950">
                        <option value="draft">Draft</option><option value="active">Active</option><option value="inactive">Inactive</option>
                    </select>
                </label>
                <div></div>
                <x-form-input label="Effective From" name="effective_from" type="date" wire:model="effective_from" />
                <x-form-input label="Effective To" name="effective_to" type="date" wire:model="effective_to" />
                <label class="block text-sm font-bold md:col-span-2">Notes
                    <textarea wire:model.blur="notes" class="mt-1 block min-h-20 w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950"></textarea>
                </label>
            </div>
        </x-card>

        @php
            $itemSections = [
                [
                    'type' => ProductionRecipeItem::TYPE_INVENTORY,
                    'title' => __('production.recipes.form.inventory_materials'),
                    'description' => __('production.recipes.form.inventory_materials_help'),
                ],
                [
                    'type' => ProductionRecipeItem::TYPE_NON_INVENTORY,
                    'title' => __('production.recipes.form.non_inventory_costs'),
                    'description' => __('production.recipes.form.non_inventory_tooltip'),
                ],
            ];
        @endphp

        @foreach ($itemSections as $section)
        <x-card :title="$section['title']" :description="$section['description']">
            @if ($section['type'] === ProductionRecipeItem::TYPE_INVENTORY)
            <div class="mb-5 grid gap-3 rounded-2xl border border-cyan-200 bg-cyan-50/70 p-4 dark:border-cyan-500/30 dark:bg-cyan-500/10 sm:grid-cols-2 sm:p-5">
                <div>
                    <p class="text-xs font-black uppercase tracking-wide text-cyan-700 dark:text-cyan-300">{{ __('production.recipes.form.recipe_output') }}</p>
                    <p class="mt-1 text-2xl font-black text-slate-950 dark:text-white">{{ $this->formatAuthoringDecimal($output_quantity) }} {{ $outputUnitLabel }}</p>
                </div>
                <div class="sm:border-l sm:border-cyan-200 sm:pl-5 dark:sm:border-cyan-500/30">
                    <p class="text-xs font-black uppercase tracking-wide text-cyan-700 dark:text-cyan-300">{{ __('production.recipes.form.recipe_basis') }}</p>
                    <p class="mt-1 text-sm font-bold leading-6 text-slate-700 dark:text-slate-200">{{ __($headerSummaryKey, ['quantity' => $this->formatAuthoringDecimal($output_quantity), 'product' => $selectedFinishedProduct?->name]) }}</p>
                </div>
            </div>
            @error('items') <p class="mb-3 rounded-lg bg-red-50 p-3 text-sm font-bold text-red-700">{{ $message }}</p> @enderror
            @endif
            <div class="space-y-4">
                @foreach ($items as $index => $item)
                    @php
                        $rowUuid = $item['uuid'] ?? 'row-'.$index;
                        $isInventory = ($item['cost_type'] ?? null) === ProductionRecipeItem::TYPE_INVENTORY;
                        $itemMaterial = $materialProductsById->get((int) ($item['material_product_id'] ?? 0));
                        $itemName = $isInventory ? $itemMaterial?->name : trim((string) ($item['cost_name'] ?? ''));
                        $itemName = $itemName ?: ($isInventory ? __('production.recipes.form.selected_material') : __('production.recipes.form.non_inventory_cost'));
                        $itemUnit = $recipeUnits->firstWhere('id', (int) ($item['material_unit_id'] ?? 0));
                        $itemUnitLabel = $itemUnit?->short_name ?: __('production.recipes.form.units');
                        $equivalence = $this->basisEquivalence($item);
                        $perRecipeBasis = ($item['basis'] ?? 'recipe_output') === 'recipe_output';
                        $basisLabel = $perRecipeBasis ? __('production.recipes.form.per_recipe_output') : __('production.recipes.form.per_one_finished_unit');
                        $rowHasEnteredData = filled($item['material_product_id'] ?? null)
                            || filled($item['cost_name'] ?? null)
                            || filled($item['source_quantity'] ?? null)
                            || filled($item['unit_cost'] ?? null)
                            || filled($item['notes'] ?? null);
                    @endphp
                    @continue(($item['cost_type'] ?? null) !== $section['type'])
                    <section wire:key="recipe-item-{{ $rowUuid }}" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                        <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex items-center gap-2">
                                    <h3 class="font-black">{{ $isInventory ? __('production.recipes.form.inventory_material') : __('production.recipes.form.non_inventory_cost') }}</h3>
                                    <span tabindex="0" title="{{ $isInventory ? __('production.recipes.form.inventory_tooltip') : __('production.recipes.form.non_inventory_tooltip') }}" aria-label="{{ $isInventory ? __('production.recipes.form.inventory_tooltip') : __('production.recipes.form.non_inventory_tooltip') }}" class="grid h-5 w-5 cursor-help place-items-center rounded-full bg-slate-100 text-xs font-black text-slate-600 outline-none ring-cyan-500 focus:ring-2 dark:bg-white/10 dark:text-slate-200">?</span>
                                </div>
                                <p class="mt-1 text-xs text-slate-500">{{ $isInventory ? __('production.recipes.form.inventory_tooltip') : __('production.recipes.form.non_inventory_tooltip') }}</p>
                                @if ($item['legacy_authoring'] ?? false)
                                    <p class="mt-1 text-xs font-bold text-amber-700 dark:text-amber-300">{{ __('production.recipes.validation.legacy_authoring_unavailable') }}</p>
                                @endif
                            </div>
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-500 dark:bg-white/10">{{ $basisLabel }}</span>
                        </div>
                        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                            @if ($isInventory)
                                <label class="block text-sm font-bold" for="material-product-{{ $rowUuid }}">{{ __('production.recipes.form.material_product') }}
                                    <select id="material-product-{{ $rowUuid }}" wire:model.live="items.{{ $index }}.material_product_id" aria-describedby="material-product-error-{{ $rowUuid }}" class="mt-1 block min-h-11 w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950">
                                        <option value="">{{ __('production.recipes.form.select_material') }}</option>
                                        @foreach ($materialProducts as $material)
                                            <option value="{{ $material->id }}">{{ $material->name }}{{ $material->isManufactured() ? ' (manufactured)' : '' }}</option>
                                        @endforeach
                                    </select>
                                    @error("items.{$index}.material_product_id") <span id="material-product-error-{{ $rowUuid }}" class="text-xs text-red-600">{{ $message }}</span> @enderror
                                </label>
                            @else
                                <x-form-input label="Cost Name" name="items.{{ $index }}.cost_name" wire:model.blur="items.{{ $index }}.cost_name" placeholder="Water, Electricity, Labour" />
                            @endif

                            <fieldset class="md:col-span-2 xl:col-span-2">
                                <legend class="text-sm font-black text-slate-800 dark:text-slate-100">{{ __('production.recipes.form.recipe_basis') }}</legend>
                                <div class="mt-1 grid gap-2 sm:grid-cols-2">
                                    <label class="flex min-h-11 cursor-pointer items-start gap-2 rounded-xl border p-2.5 transition focus-within:ring-2 focus-within:ring-cyan-500 {{ ! $perRecipeBasis ? 'border-cyan-500 bg-cyan-50 ring-1 ring-cyan-500/20 dark:bg-cyan-500/10' : 'border-slate-200 dark:border-slate-700' }}">
                                        <input type="radio" wire:model.live="items.{{ $index }}.basis" value="finished_unit" class="mt-0.5 border-slate-300 text-cyan-600 focus:ring-cyan-500">
                                        <span><span class="block text-sm font-black">{{ __('production.recipes.form.per_one_finished_unit') }}</span><span class="mt-0.5 block text-xs leading-4 text-slate-500">{{ __('production.recipes.form.per_one_finished_unit_help') }}</span></span>
                                    </label>
                                    <label class="flex min-h-11 cursor-pointer items-start gap-2 rounded-xl border p-2.5 transition focus-within:ring-2 focus-within:ring-cyan-500 {{ $perRecipeBasis ? 'border-cyan-500 bg-cyan-50 ring-1 ring-cyan-500/20 dark:bg-cyan-500/10' : 'border-slate-200 dark:border-slate-700' }}">
                                        <input type="radio" wire:model.live="items.{{ $index }}.basis" value="recipe_output" class="mt-0.5 border-slate-300 text-cyan-600 focus:ring-cyan-500">
                                        <span><span class="block text-sm font-black">{{ __('production.recipes.form.per_recipe_output') }}</span><span class="mt-0.5 block text-xs leading-4 text-slate-500">{{ __('production.recipes.form.per_recipe_output_help', ['quantity' => $this->formatAuthoringDecimal($output_quantity), 'unit' => $outputUnitLabel]) }}</span></span>
                                    </label>
                                </div>
                                @error("items.{$index}.basis") <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                            </fieldset>

                            <x-form-input
                                :label="$isInventory ? __('production.recipes.form.quantity') : __('production.recipes.form.quantity_optional')"
                                name="items.{{ $index }}.source_quantity"
                                type="number" min="0.000000000001" step="any"
                                wire:model.live.debounce.300ms="items.{{ $index }}.source_quantity"
                            />
                            @if ($isInventory)
                            <div class="block text-sm font-bold" role="status" aria-live="polite" aria-describedby="material-unit-error-{{ $rowUuid }}">
                                <span>{{ __('production.recipes.form.unit') }}</span>
                                <div class="mt-1 flex min-h-11 items-center rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm font-black text-slate-800 dark:border-slate-700 dark:bg-white/5 dark:text-slate-100">
                                    {{ $itemUnit?->name ? $itemUnit->name.' ('.$itemUnit->short_name.')' : __('production.recipes.form.select_material_for_unit') }}
                                </div>
                                @error("items.{$index}.material_unit_id") <span id="material-unit-error-{{ $rowUuid }}" class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                                @if (! $errors->has("items.{$index}.material_unit_id") && filled($item['material_product_id'] ?? null) && ! $itemUnit)
                                    <span id="material-unit-error-{{ $rowUuid }}" class="mt-1 block text-xs text-red-600">{{ __('production.recipes.validation.stock_unit_missing') }}</span>
                                @endif
                            </div>
                            @else
                            <label class="block text-sm font-bold" for="non-inventory-unit-{{ $rowUuid }}">{{ __('production.recipes.form.unit') }} {{ __('production.recipes.form.for_quantity') }}
                                <select id="non-inventory-unit-{{ $rowUuid }}" wire:model.live="items.{{ $index }}.material_unit_id" class="mt-1 block min-h-11 w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950">
                                    <option value="">{{ __('production.recipes.form.select_unit') }}</option>
                                    @foreach ($recipeUnits as $unit)
                                        <option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->short_name }})</option>
                                    @endforeach
                                </select>
                                @error("items.{$index}.material_unit_id") <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                            </label>
                            @endif
                            @if (! $isInventory)
                            <label class="block text-sm font-bold" for="production-cost-{{ $rowUuid }}">
                                {{ __('production.recipes.form.production_cost_per_basis') }}
                                <span class="mt-0.5 block text-xs font-semibold text-slate-500">{{ $basisLabel }}</span>
                                <span class="mt-1 flex min-h-11 overflow-hidden rounded-lg border border-slate-200 bg-white focus-within:border-cyan-500 focus-within:ring-2 focus-within:ring-cyan-500/20 dark:border-slate-700 dark:bg-navy-950">
                                    <span class="flex items-center border-r border-slate-200 bg-slate-50 px-3 text-sm font-black text-slate-600 dark:border-slate-700 dark:bg-white/5 dark:text-slate-300">TZS</span>
                                    <input id="production-cost-{{ $rowUuid }}" type="number" min="0" step="0.0001" wire:model.blur="items.{{ $index }}.unit_cost" class="min-w-0 flex-1 border-0 bg-transparent px-3 py-2 text-sm focus:ring-0">
                                </span>
                                @error("items.{$index}.unit_cost") <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                            </label>
                            @endif

                            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm leading-6 text-emerald-950 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100 md:col-span-2 xl:col-span-4" aria-live="polite">
                                <p class="font-black text-emerald-700 dark:text-emerald-300">{{ __('production.recipes.form.live_explanation') }}</p>
                                @if ($equivalence['quantity'] !== null)
                                    <p class="mt-1 font-bold">
                                        @if ($perRecipeBasis)
                                            {{ __('production.recipes.form.recipe_uses_quantity_per_output', ['quantity' => $this->formatAuthoringDecimal($item['source_quantity']), 'unit' => $itemUnitLabel, 'material' => $itemName, 'output' => $this->formatAuthoringDecimal($output_quantity), 'output_unit' => $outputUnitLabel, 'product' => $finishedProductLabel]) }}
                                        @else
                                            {{ __('production.recipes.form.recipe_uses_quantity_per_unit', ['quantity' => $this->formatAuthoringDecimal($item['source_quantity']), 'unit' => $itemUnitLabel, 'material' => $itemName, 'product' => $finishedProductLabel]) }}
                                        @endif
                                    </p>
                                    <p class="font-semibold text-emerald-800 dark:text-emerald-200">
                                        @if ($perRecipeBasis)
                                            {{ __('production.recipes.form.equivalent_quantity_per_unit', ['quantity' => $this->formatAuthoringDecimal($equivalence['quantity']), 'unit' => $itemUnitLabel]) }}
                                        @else
                                            {{ __('production.recipes.form.equivalent_quantity_per_output', ['quantity' => $this->formatAuthoringDecimal($equivalence['quantity']), 'unit' => $itemUnitLabel, 'output' => $this->formatAuthoringDecimal($output_quantity), 'output_unit' => $outputUnitLabel]) }}
                                        @endif
                                    </p>
                                @endif
                                @if ($equivalence['cost'] !== null)
                                    <p class="{{ $equivalence['quantity'] !== null ? 'mt-2 border-t border-emerald-200 pt-2 dark:border-emerald-500/30' : 'mt-1' }} font-bold">
                                        @if ($perRecipeBasis)
                                            {{ __('production.recipes.form.recipe_cost_per_output', ['name' => $itemName, 'cost' => $this->formatAuthoringMoney($item['unit_cost']), 'output' => $this->formatAuthoringDecimal($output_quantity), 'output_unit' => $outputUnitLabel]) }}
                                        @else
                                            {{ __('production.recipes.form.recipe_cost_per_unit', ['name' => $itemName, 'cost' => $this->formatAuthoringMoney($item['unit_cost']), 'product' => $finishedProductLabel]) }}
                                        @endif
                                    </p>
                                    <p class="font-semibold text-emerald-800 dark:text-emerald-200">
                                        @if ($perRecipeBasis)
                                            {{ __('production.recipes.form.equivalent_cost_per_unit', ['cost' => $this->formatAuthoringMoney($equivalence['cost'])]) }}
                                        @else
                                            {{ __('production.recipes.form.equivalent_cost_per_output', ['cost' => $this->formatAuthoringMoney($equivalence['cost']), 'output' => $this->formatAuthoringDecimal($output_quantity), 'output_unit' => $outputUnitLabel]) }}
                                        @endif
                                    </p>
                                @endif
                                @if ($equivalence['quantity'] === null && $equivalence['cost'] === null)
                                    <p class="mt-1 text-xs font-semibold text-emerald-800 dark:text-emerald-200">{{ __('production.recipes.form.enter_value_for_preview') }}</p>
                                @endif
                            </div>

                            <label class="block text-sm font-bold md:col-span-2 xl:col-span-4" for="item-notes-{{ $rowUuid }}">{{ __('production.recipes.form.notes') }}
                                <input id="item-notes-{{ $rowUuid }}" wire:model.blur="items.{{ $index }}.notes" class="mt-1 block min-h-11 w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950">
                            </label>
                        </div>
                        <div class="mt-3 flex justify-end border-t border-slate-100 pt-3 dark:border-slate-800">
                            <button type="button" wire:click="removeItem('{{ $rowUuid }}')" @if ($rowHasEnteredData) wire:confirm="{{ __('production.recipes.form.remove_confirmation') }}" @endif aria-label="{{ __('production.recipes.form.remove') }}" class="inline-flex min-h-10 items-center gap-2 rounded-lg px-3 py-2 text-sm font-bold text-red-600 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 dark:text-red-400 dark:hover:bg-red-500/10">
                                <span aria-hidden="true">×</span> {{ __('production.recipes.form.remove') }}
                            </button>
                        </div>
                    </section>
                @endforeach
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                @if ($section['type'] === ProductionRecipeItem::TYPE_INVENTORY)
                <button type="button" wire:click="addInventoryMaterial" wire:loading.attr="disabled" wire:target="addInventoryMaterial" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 shadow-sm transition hover:bg-slate-50 active:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900 dark:text-white dark:hover:bg-slate-800 dark:active:bg-slate-700 dark:focus:ring-offset-slate-950">
                    <span aria-hidden="true" class="text-lg font-bold leading-none text-cyan-700 dark:text-cyan-300">+</span>
                    {{ __('production.recipes.form.add_inventory_material') }}
                </button>
                @else
                <button type="button" wire:click="addNonInventoryCost" wire:loading.attr="disabled" wire:target="addNonInventoryCost" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 shadow-sm transition hover:bg-slate-50 active:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900 dark:text-white dark:hover:bg-slate-800 dark:active:bg-slate-700 dark:focus:ring-offset-slate-950">
                    <span aria-hidden="true" class="text-lg font-bold leading-none text-cyan-700 dark:text-cyan-300">+</span>
                    {{ __('production.recipes.form.add_non_inventory_cost') }}
                </button>
                @endif
            </div>
        </x-card>
        @endforeach

        <div class="sticky bottom-3 flex justify-end gap-3 rounded-xl border bg-white/95 p-4 shadow-lg backdrop-blur dark:bg-slate-900/95">
            <a href="{{ route('production.recipes.index') }}" wire:navigate class="rounded-xl border px-4 py-2.5 text-sm font-black">Cancel</a>
            <button class="rounded-xl bg-build-orange px-5 py-2.5 text-sm font-black text-white">Save Recipe</button>
        </div>
    </form>
</div>
