<?php

use App\Models\ProductionRecipe;
use App\Models\ProductionRecipeItem;
use App\Services\ProductionRecipeCalculator;
use App\Support\CompanyFeatures;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['recipe' => null, 'targetOutput' => '1', 'calculation' => []]);

mount(function (ProductionRecipe $recipe): void {
    abort_unless(
        CompanyFeatures::manufacturingEnabled()
        && (auth()->user()?->can('production.view_recipes') || auth()->user()?->can('production.manage_recipes')),
        403
    );
    abort_unless((int) $recipe->company_id === (int) CompanyFeatures::companyId(), 404);
    $this->recipe = $recipe->load(['product', 'outputUnit', 'items.materialProduct', 'items.materialUnit']);
    $this->targetOutput = '1';
    $this->calculation = app(ProductionRecipeCalculator::class)->calculate($this->recipe, $this->targetOutput);
});

$calculate = function (): void {
    try {
        $this->calculation = app(ProductionRecipeCalculator::class)->calculate($this->recipe, $this->targetOutput);
        $this->resetErrorBag('targetOutput');
    } catch (\InvalidArgumentException $exception) {
        $this->addError('targetOutput', $exception->getMessage());
    }
};

$formatDecimal = fn ($value): string => $value === null ? '—' : (rtrim(rtrim((string) $value, '0'), '.') ?: '0');

$formatMoney = function ($value): string {
    if ($value === null || $value === '') {
        return '—';
    }

    $decimal = $this->formatDecimal($value);
    [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
    $sign = str_starts_with($whole, '-') ? '-' : '';
    $whole = ltrim($whole, '-');
    $grouped = strrev(implode(',', str_split(strrev($whole === '' ? '0' : $whole), 3)));

    return $sign.$grouped.($fraction !== '' ? '.'.$fraction : '');
};

?>

<div>
    <x-page-header
        :title="$recipe->name"
        description="Normalized recipe requirements and informational calculator."
        :breadcrumbs="['Dashboard' => route('dashboard'), __('production.title') => route('production.index'), __('production.recipes.title') => route('production.recipes.index'), $recipe->name => null]"
    >
        @if (auth()->user()?->can('production.manage_recipes'))
            @if ($recipe->status !== 'active')<a href="{{ route('production.recipes.edit', $recipe) }}" wire:navigate class="erp-btn-secondary px-4 py-2.5">Edit</a>@endif
        @endif
    </x-page-header>

    @if (session('success')) <div class="mb-4 rounded-xl bg-emerald-50 p-3 text-sm font-bold text-emerald-700">{{ session('success') }}</div> @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <x-card title="Recipe Summary">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Manufactured Product</dt><dd class="font-black">{{ $recipe->product?->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Version</dt><dd>{{ $recipe->version ?: '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Status</dt><dd>{{ ucfirst($recipe->status) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Nominal Output</dt><dd>{{ $this->formatDecimal($recipe->output_quantity) }} {{ $recipe->outputUnit?->short_name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Effective</dt><dd>{{ $recipe->effective_from?->format('d M Y') ?: '—' }} – {{ $recipe->effective_to?->format('d M Y') ?: 'open' }}</dd></div>
                <div><dt class="text-slate-500">Notes</dt><dd class="mt-1">{{ $recipe->notes ?: '—' }}</dd></div>
            </dl>
        </x-card>

        <x-card title="Requirement Calculator" description="Informational only; no stock is reserved or deducted." class="xl:col-span-2">
            <form wire:submit="calculate" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <x-form-input label="Desired Finished Output" name="targetOutput" type="number" min="0.00000001" step="0.00000001" wire:model.blur="targetOutput" />
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Calculate Requirements</button>
            </form>
            <p class="mt-3 text-xs font-bold text-amber-700">Preview only — this does not create an order, consume material, or add finished goods.</p>
        </x-card>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <x-card title="Inventory Materials">
            <x-table :headers="['Material', 'Configured', 'Equivalent', 'Target Requirement']">
                @forelse ($recipe->items->where('cost_type', ProductionRecipeItem::TYPE_INVENTORY) as $item)
                    @php
                        $result = collect($calculation['materials'] ?? [])->firstWhere('item_id', $item->id);
                        $hasAuthoringMetadata = in_array($item->authoring_basis, ProductionRecipeItem::AUTHORING_BASES, true);
                        $configuredBasis = $hasAuthoringMetadata
                            ? $item->authoring_basis
                            : ProductionRecipeItem::AUTHORING_PER_FINISHED_UNIT;
                        $configuredQuantity = $hasAuthoringMetadata && $item->authoring_quantity !== null
                            ? $item->authoring_quantity
                            : $item->normalized_quantity;
                        $recipeQuantity = $item->normalized_quantity !== null
                            ? bcmul((string) $item->normalized_quantity, (string) $recipe->output_quantity, ProductionRecipeCalculator::QUANTITY_SCALE)
                            : null;
                        $equivalentQuantity = $configuredBasis === ProductionRecipeItem::AUTHORING_PER_RECIPE_OUTPUT
                            ? $item->normalized_quantity
                            : $recipeQuantity;
                        $unitLabel = $item->materialUnit?->short_name;
                        $outputLabel = $this->formatDecimal($recipe->output_quantity).' '.($recipe->outputUnit?->short_name);
                    @endphp
                    <tr>
                        <td class="px-4 py-3 align-top"><p class="font-black">{{ $item->materialProduct?->name }}</p><p class="text-xs text-slate-500">{{ $item->notes }}</p></td>
                        <td class="px-4 py-3 align-top text-sm">
                            <p><span class="font-bold text-slate-500">Configured:</span> <span class="font-black">{{ $this->formatDecimal($configuredQuantity) }} {{ $unitLabel }} {{ $configuredBasis === ProductionRecipeItem::AUTHORING_PER_RECIPE_OUTPUT ? 'per recipe output' : 'per finished unit' }}</span></p>
                            @if (! $hasAuthoringMetadata)
                                <p class="mt-1 text-xs font-bold text-amber-700 dark:text-amber-300">{{ __('production.recipes.validation.legacy_authoring_unavailable') }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 align-top text-sm">
                            <span class="font-bold text-slate-500">Equivalent:</span>
                            <span class="font-black">{{ $this->formatDecimal($equivalentQuantity) }} {{ $unitLabel }} {{ $configuredBasis === ProductionRecipeItem::AUTHORING_PER_RECIPE_OUTPUT ? 'per finished unit' : 'per recipe output ('.$outputLabel.')' }}</span>
                        </td>
                        <td class="px-4 py-3 align-top font-black text-build-orange">
                            <span class="block text-xs font-bold uppercase text-slate-500">Target Requirement</span>
                            {{ $this->formatDecimal($result['required_quantity'] ?? null) }} {{ $unitLabel }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">No inventory materials.</td></tr>
                @endforelse
            </x-table>
        </x-card>

        <x-card title="Non-Inventory Inputs and Costs" description="Configured values preserve the original entry basis when authoring metadata is available.">
            <x-table :headers="['Input', 'Configured', 'Equivalent', 'Target Requirement', 'Target Cost']">
                @forelse ($recipe->items->where('cost_type', ProductionRecipeItem::TYPE_NON_INVENTORY) as $item)
                    @php
                        $result = collect($calculation['non_inventory_costs'] ?? [])->firstWhere('item_id', $item->id);
                        $hasAuthoringMetadata = in_array($item->authoring_basis, ProductionRecipeItem::AUTHORING_BASES, true);
                        $configuredBasis = $hasAuthoringMetadata
                            ? $item->authoring_basis
                            : ProductionRecipeItem::AUTHORING_PER_FINISHED_UNIT;
                        $configuredQuantity = $hasAuthoringMetadata && $item->authoring_quantity !== null
                            ? $item->authoring_quantity
                            : $item->normalized_quantity;
                        $configuredCost = $hasAuthoringMetadata && $item->authoring_unit_cost !== null
                            ? $item->authoring_unit_cost
                            : $item->unit_cost;
                        $recipeQuantity = $item->normalized_quantity !== null
                            ? bcmul((string) $item->normalized_quantity, (string) $recipe->output_quantity, ProductionRecipeCalculator::QUANTITY_SCALE)
                            : null;
                        $recipeCost = $item->unit_cost !== null
                            ? bcmul((string) $item->unit_cost, (string) $recipe->output_quantity, ProductionRecipeCalculator::COST_SCALE)
                            : null;
                        $unitLabel = $item->materialUnit?->short_name;
                        $outputLabel = $this->formatDecimal($recipe->output_quantity).' '.($recipe->outputUnit?->short_name);
                        $equivalentQuantity = $configuredBasis === ProductionRecipeItem::AUTHORING_PER_RECIPE_OUTPUT
                            ? $item->normalized_quantity
                            : $recipeQuantity;
                        $equivalentCost = $configuredBasis === ProductionRecipeItem::AUTHORING_PER_RECIPE_OUTPUT
                            ? $item->unit_cost
                            : $recipeCost;
                        $configuredBasisLabel = $configuredBasis === ProductionRecipeItem::AUTHORING_PER_RECIPE_OUTPUT
                            ? 'per recipe output'
                            : 'per finished unit';
                        $equivalentBasisLabel = $configuredBasis === ProductionRecipeItem::AUTHORING_PER_RECIPE_OUTPUT
                            ? 'per finished unit'
                            : 'per recipe output ('.$outputLabel.')';
                    @endphp
                    <tr>
                        <td class="px-4 py-3 align-top"><p class="font-black">{{ $item->cost_name }}</p><p class="mt-1 text-xs text-slate-500">{{ $item->notes }}</p></td>
                        <td class="px-4 py-3 align-top text-sm">
                            @if ($configuredQuantity !== null)
                                <p><span class="font-bold text-slate-500">Configured:</span> <span class="font-black">{{ $this->formatDecimal($configuredQuantity) }} {{ $unitLabel }} {{ $configuredBasisLabel }}</span></p>
                            @endif
                            @if ($configuredCost !== null)
                                <p class="{{ $configuredQuantity !== null ? 'mt-2' : '' }}"><span class="font-bold text-slate-500">Configured:</span> <span class="font-black">TZS {{ $this->formatMoney($configuredCost) }} {{ $configuredBasisLabel }}</span></p>
                            @endif
                            @if (! $hasAuthoringMetadata)
                                <p class="mt-1 text-xs font-bold text-amber-700 dark:text-amber-300">{{ __('production.recipes.validation.legacy_authoring_unavailable') }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 align-top text-sm">
                            @if ($equivalentQuantity !== null)
                                <p><span class="font-bold text-slate-500">Equivalent:</span> <span class="font-black">{{ $this->formatDecimal($equivalentQuantity) }} {{ $unitLabel }} {{ $equivalentBasisLabel }}</span></p>
                            @endif
                            @if ($equivalentCost !== null)
                                <p class="{{ $equivalentQuantity !== null ? 'mt-2' : '' }}"><span class="font-bold text-slate-500">Equivalent:</span> <span class="font-black">TZS {{ $this->formatMoney($equivalentCost) }} {{ $equivalentBasisLabel }}</span></p>
                            @endif
                        </td>
                        <td class="px-4 py-3 align-top font-black text-build-orange">
                            @if ($item->normalized_quantity !== null)
                                <span class="block text-xs font-bold uppercase text-slate-500">Target Requirement</span>
                                {{ $this->formatDecimal($result['required_quantity'] ?? null) }} {{ $unitLabel }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 align-top font-black text-build-orange">
                            @if ($item->unit_cost !== null)
                                <span class="block text-xs font-bold uppercase text-slate-500">Target Cost</span>
                                TZS {{ $this->formatMoney($result['total_cost'] ?? null) }}
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">No non-inventory inputs.</td></tr>
                @endforelse
            </x-table>
            <div class="mt-4 rounded-xl bg-slate-100 p-4 text-right dark:bg-white/5">
                <p class="text-xs font-bold uppercase text-slate-500">Estimated direct non-inventory cost</p>
                <p class="mt-1 text-xl font-black">TZS {{ $this->formatMoney($calculation['direct_non_inventory_cost'] ?? '0') }}</p>
            </div>
        </x-card>
    </div>
</div>
