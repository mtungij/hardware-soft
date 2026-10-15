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
        $this->addError('targetOutput', \App\Support\UiText::feedback($exception->getMessage()));
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
         :translate-title="false" description="{{ __('application.normalized_recipe_requirements_and_informational_calculator_71673614') }}"
        :breadcrumbs="['Dashboard' => route('dashboard'), __('production.title') => route('production.index'), __('production.recipes.title') => route('production.recipes.index'), $recipe->name => null]"
    >
        @if (auth()->user()?->can('production.manage_recipes'))
            @if ($recipe->status !== 'active')<a href="{{ route('production.recipes.edit', $recipe) }}" wire:navigate class="erp-btn-secondary px-4 py-2.5">{{ __('application.edit_5301648d') }}</a>@endif
        @endif
    </x-page-header>

    @if (session('success')) <div class="mb-4 rounded-xl bg-emerald-50 p-3 text-sm font-bold text-emerald-700">{{ session('success') }}</div> @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <x-card title="{{ __('application.recipe_summary_6f5ccd68') }}">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.manufactured_product_37b24a6e') }}</dt><dd class="font-black">{{ $recipe->product?->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.version_2da600bf') }}</dt><dd>{{ $recipe->version ?: '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.status_bae7d5be') }}</dt><dd>{{ \App\Support\UiText::status($recipe->status) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.nominal_output_cc0c31ae') }}</dt><dd>{{ $this->formatDecimal($recipe->output_quantity) }} {{ $recipe->outputUnit?->short_name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('application.effective_b034605d') }}</dt><dd>{{ $recipe->effective_from?->locale(app()->getLocale())->translatedFormat('d M Y') ?: '—' }} – {{ $recipe->effective_to?->locale(app()->getLocale())->translatedFormat('d M Y') ?: 'open' }}</dd></div>
                <div><dt class="text-slate-500">{{ __('application.notes_70440046') }}</dt><dd class="mt-1">{{ $recipe->notes ?: '—' }}</dd></div>
            </dl>
        </x-card>

        <x-card title="{{ __('application.requirement_calculator_77009cc2') }}" description="{{ __('application.informational_only_no_stock_is_reserved_or_deducted_a8fca238') }}" class="xl:col-span-2">
            <form wire:submit="calculate" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <x-form-input label="{{ __('application.desired_finished_output_d644082e') }}" name="targetOutput" type="number" min="0.00000001" step="0.00000001" wire:model.blur="targetOutput" />
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">{{ __('application.calculate_requirements_cf92302d') }}</button>
            </form>
            <p class="mt-3 text-xs font-bold text-amber-700">{{ __('application.preview_only_this_does_not_create_an_order_consume_material_or_add_finished_f4e50e19') }}</p>
        </x-card>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <x-card title="{{ __('application.inventory_materials_29940b2d') }}">
            <x-table :headers="[__('application.material_d8169782'), __('application.configured_668c5fff'), __('application.equivalent_1f55cac8'), __('application.target_requirement_332eff6f')]">
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
                            <p><span class="font-bold text-slate-500">{{ __('application.configured_846050da') }}</span> <span class="font-black">{{ $this->formatDecimal($configuredQuantity) }} {{ $unitLabel }} {{ $configuredBasis === ProductionRecipeItem::AUTHORING_PER_RECIPE_OUTPUT ? 'per recipe output' : 'per finished unit' }}</span></p>
                            @if (! $hasAuthoringMetadata)
                                <p class="mt-1 text-xs font-bold text-amber-700 dark:text-amber-300">{{ __('production.recipes.validation.legacy_authoring_unavailable') }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 align-top text-sm">
                            <span class="font-bold text-slate-500">{{ __('application.equivalent_9b5b165b') }}</span>
                            <span class="font-black">{{ $this->formatDecimal($equivalentQuantity) }} {{ $unitLabel }} {{ $configuredBasis === ProductionRecipeItem::AUTHORING_PER_RECIPE_OUTPUT ? 'per finished unit' : 'per recipe output ('.$outputLabel.')' }}</span>
                        </td>
                        <td class="px-4 py-3 align-top font-black text-build-orange">
                            <span class="block text-xs font-bold uppercase text-slate-500">{{ __('application.target_requirement_332eff6f') }}</span>
                            {{ $this->formatDecimal($result['required_quantity'] ?? null) }} {{ $unitLabel }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_inventory_materials_6d894425') }}</td></tr>
                @endforelse
            </x-table>
        </x-card>

        <x-card title="{{ __('application.non_inventory_inputs_and_costs_8e4ab9c5') }}" description="{{ __('application.configured_values_preserve_the_original_entry_basis_when_authoring_metadata_8306a074') }}">
            <x-table :headers="[__('terminology.input_b568d47f'), __('application.configured_668c5fff'), __('application.equivalent_1f55cac8'), __('application.target_requirement_332eff6f'), __('application.target_cost_edad8124')]">
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
                                <p><span class="font-bold text-slate-500">{{ __('application.configured_846050da') }}</span> <span class="font-black">{{ $this->formatDecimal($configuredQuantity) }} {{ $unitLabel }} {{ $configuredBasisLabel }}</span></p>
                            @endif
                            @if ($configuredCost !== null)
                                <p class="{{ $configuredQuantity !== null ? 'mt-2' : '' }}"><span class="font-bold text-slate-500">{{ __('application.configured_846050da') }}</span> <span class="font-black">TZS {{ $this->formatMoney($configuredCost) }} {{ $configuredBasisLabel }}</span></p>
                            @endif
                            @if (! $hasAuthoringMetadata)
                                <p class="mt-1 text-xs font-bold text-amber-700 dark:text-amber-300">{{ __('production.recipes.validation.legacy_authoring_unavailable') }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 align-top text-sm">
                            @if ($equivalentQuantity !== null)
                                <p><span class="font-bold text-slate-500">{{ __('application.equivalent_9b5b165b') }}</span> <span class="font-black">{{ $this->formatDecimal($equivalentQuantity) }} {{ $unitLabel }} {{ $equivalentBasisLabel }}</span></p>
                            @endif
                            @if ($equivalentCost !== null)
                                <p class="{{ $equivalentQuantity !== null ? 'mt-2' : '' }}"><span class="font-bold text-slate-500">{{ __('application.equivalent_9b5b165b') }}</span> <span class="font-black">TZS {{ $this->formatMoney($equivalentCost) }} {{ $equivalentBasisLabel }}</span></p>
                            @endif
                        </td>
                        <td class="px-4 py-3 align-top font-black text-build-orange">
                            @if ($item->normalized_quantity !== null)
                                <span class="block text-xs font-bold uppercase text-slate-500">{{ __('application.target_requirement_332eff6f') }}</span>
                                {{ $this->formatDecimal($result['required_quantity'] ?? null) }} {{ $unitLabel }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 align-top font-black text-build-orange">
                            @if ($item->unit_cost !== null)
                                <span class="block text-xs font-bold uppercase text-slate-500">{{ __('application.target_cost_edad8124') }}</span>
                                TZS {{ $this->formatMoney($result['total_cost'] ?? null) }}
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_non_inventory_inputs_45727181') }}</td></tr>
                @endforelse
            </x-table>
            <div class="mt-4 rounded-xl bg-slate-100 p-4 text-right dark:bg-white/5">
                <p class="text-xs font-bold uppercase text-slate-500">{{ __('application.estimated_direct_non_inventory_cost_d0fb8228') }}</p>
                <p class="mt-1 text-xl font-black">TZS {{ $this->formatMoney($calculation['direct_non_inventory_cost'] ?? '0') }}</p>
            </div>
        </x-card>
    </div>
</div>
