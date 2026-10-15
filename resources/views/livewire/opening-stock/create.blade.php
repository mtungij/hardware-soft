<?php

use App\Models\Branch;
use App\Models\Product;
use App\Services\OpeningStockService;
use App\Services\ProductUnitConversionService;
use App\Support\AuthorizationScope;
use App\Support\InventorySettings;
use Illuminate\Support\Str;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'branch_id' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''),
    'stock_location_id' => '',
    'opening_date' => '',
    'notes' => '',
    'lines' => [],
]);

mount(function (): void {
    abort_unless(InventorySettings::warehouseEnabled() && auth()->user()->can('opening_stock.create'), 403);
    $this->branch_id = (string) (auth()->user()->branch_id ?: Branch::query()->value('id'));
    $this->opening_date = today()->toDateString();
    $this->lines = [[
        'key' => (string) Str::uuid(), 'product_id' => '', 'product_unit_conversion_id' => '',
        'quantity' => '', 'unit_cost' => '', 'cost_edited' => false, 'batch_number' => '', 'expiry_date' => '', 'notes' => '',
    ]];
});

$addLine = function (): void {
    $this->lines[] = [
        'key' => (string) Str::uuid(), 'product_id' => '', 'product_unit_conversion_id' => '',
        'quantity' => '', 'unit_cost' => '', 'cost_edited' => false, 'batch_number' => '', 'expiry_date' => '', 'notes' => '',
    ];
};

$removeLine = function (int $index): void {
    unset($this->lines[$index]);
    $this->lines = array_values($this->lines);
};

$updatedBranchId = function (): void {
    $this->stock_location_id = '';
};

$updatedLines = function (mixed $value, string $key): void {
    if (preg_match('/^(\d+)\.unit_cost$/', $key, $matches) && isset($this->lines[(int) $matches[1]])) {
        $this->lines[(int) $matches[1]]['cost_edited'] = true;
    }
};

$selectProduct = function (int $index, string $productId): void {
    if (! isset($this->lines[$index])) {
        return;
    }

    $this->lines[$index]['product_id'] = $productId;
    $this->lines[$index]['product_unit_conversion_id'] = '';

    if (! ($this->lines[$index]['cost_edited'] ?? false)) {
        $product = filled($productId) ? Product::query()->where('company_id', auth()->user()->company_id)
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $this->branch_id))
            ->find($productId) : null;
        $this->lines[$index]['unit_cost'] = $product && (float) $product->buying_price > 0
            ? (string) $product->buying_price : '';
    }
};

$selectUnit = function (int $index, string $selection): void {
    if (! isset($this->lines[$index])) {
        return;
    }

    $product = Product::query()->where('company_id', auth()->user()->company_id)
        ->where('status', 'active')
        ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $this->branch_id))
        ->find($this->lines[$index]['product_id'] ?? null);
    if (! $product) {
        return;
    }

    $conversion = filled($selection)
        ? app(ProductUnitConversionService::class)->purchasable($product)->firstWhere('id', (int) $selection)
        : null;
    if (filled($selection) && ! $conversion) {
        return;
    }

    $this->lines[$index]['product_unit_conversion_id'] = $conversion ? (string) $conversion->id : '';
    if (! ($this->lines[$index]['cost_edited'] ?? false)) {
        $factor = $conversion ? (float) $conversion->conversion_factor : 1;
        $suggestedCost = $conversion && (float) $conversion->purchase_price > 0
            ? (float) $conversion->purchase_price
            : (float) $product->buying_price * $factor;
        $this->lines[$index]['unit_cost'] = $suggestedCost > 0 ? number_format($suggestedCost, 2, '.', '') : '';
    }
};

$save = function (OpeningStockService $service): void {
    abort_unless(auth()->user()->can('opening_stock.create') && InventorySettings::warehouseEnabled(), 403);
    $opening = $service->create([
        'branch_id' => $this->branch_id,
        'stock_location_id' => $this->stock_location_id,
        'opening_date' => $this->opening_date,
        'notes' => $this->notes,
        'lines' => array_map(fn (array $row): array => collect($row)->except(['key', 'cost_edited'])->all(), $this->lines),
    ], auth()->user());
    session()->flash('success', $opening->reference_number.' imehifadhiwa.');
    $this->redirectRoute('opening-stock.show', $opening, navigate: true);
};
?>
<div>
    <x-page-header title="{{ __('application.enter_opening_stock_bf52e9bb') }}" description="{{ __('application.stock_owned_before_you_started_using_hardex_e924617b') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Opening Stock' => route('opening-stock.index'), 'Ingiza' => null]" />
    @php
        $user = auth()->user();
        $companyScope = AuthorizationScope::scopeFor($user, 'stock_scope', AuthorizationScope::ASSIGNED_LOCATIONS) === AuthorizationScope::COMPANY;
        $branches = Branch::query()->where('company_id', $user->company_id)->where('status', 'active')
            ->when(! $companyScope, fn ($query) => $query->whereKey($user->branch_id))->orderBy('name')->get();
        $locations = filled($branch_id) ? app(OpeningStockService::class)->eligibleLocations($user, (int) $branch_id) : collect();
        $products = Product::query()->with('unit')->where('company_id', $user->company_id)->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $branch_id))->orderBy('name')->get();
        $productIds = collect($lines)->pluck('product_id')->filter()->unique();
        $totalBase = 0;
        $totalValue = 0;
        foreach ($lines as $row) {
            $selected = $products->firstWhere('id', (int) ($row['product_id'] ?? 0));
            $conversion = filled($row['product_unit_conversion_id'] ?? null)
                ? $selected?->unitConversions()->whereKey((int) $row['product_unit_conversion_id'])->first()
                : null;
            $quantity = is_numeric($row['quantity'] ?? null) ? (float) $row['quantity'] : 0;
            $cost = is_numeric($row['unit_cost'] ?? null) ? (float) $row['unit_cost'] : 0;
            $factor = $conversion ? (float) $conversion->conversion_factor : 1;
            $totalBase += $quantity * $factor;
            $totalValue += round($quantity * $factor * round($cost / $factor, 2), 2);
        }
    @endphp

    <div class="mb-5 rounded-xl border border-cyan-200 bg-cyan-50 p-4 text-sm text-cyan-900">{{ __('application.opening_stock_records_goods_the_business_already_owned_before_hardex_it_cre_e198d5c4') }}</div>
    <form wire:submit="save" class="space-y-5">
        <x-card title="{{ __('application.opening_stock_details_1ba67dcd') }}">
            <div class="grid gap-4 md:grid-cols-2">
                <label class="block text-sm font-bold">{{ __('application.branch_1627510b') }}
                    <select wire:model.live="branch_id" class="erp-input mt-1" @disabled(\App\Support\BranchAccess::restricted())><option value="">{{ __('application.select_branch_fcf716ea') }}</option>@foreach ($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
                    @error('branch_id') <span class="erp-error">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm font-bold">{{ __('application.store_location_16f35952') }}
                    <select wire:model="stock_location_id" class="erp-input mt-1"><option value="">{{ __('application.select_receiving_location_fb249434') }}</option>@foreach ($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select>
                    @error('stock_location_id') <span class="erp-error">{{ $message }}</span> @enderror
                </label>
                <x-form-input label="{{ __('application.start_date_9d7ab1a5') }}" name="opening_date" type="date" wire:model.live="opening_date" required />
                <label class="block text-sm font-bold">{{ __('application.reference_number_74195db5') }}
                    <div class="erp-input mt-1 bg-slate-100">{{ filled($opening_date) ? app(OpeningStockService::class)->nextReference($user, $opening_date) : __('terminology.assigned_when_saved_a77bb453') }}</div>
                    <span class="text-xs text-slate-500">{{ __('application.the_final_number_is_assigned_when_you_save_c978f55c') }}</span>
                </label>
            </div>
            <label class="mt-4 block text-sm font-bold">{{ __('application.notes_70440046') }}<textarea wire:model="notes" class="erp-input mt-1 min-h-20"></textarea></label>
        </x-card>

        <x-card title="{{ __('application.stock_you_already_owned_eaa4e53a') }}">
            <div class="space-y-4">
                @foreach ($lines as $index => $row)
                    @php
                        $selectedProduct = $products->firstWhere('id', (int) ($row['product_id'] ?? 0));
                        $unitOptions = $selectedProduct ? app(ProductUnitConversionService::class)->purchasable($selectedProduct) : collect();
                        $rowConversion = filled($row['product_unit_conversion_id'] ?? null) ? $unitOptions->firstWhere('id', (int) $row['product_unit_conversion_id']) : null;
                        $rowFactor = $rowConversion ? (float) $rowConversion->conversion_factor : 1;
                        $rowSuggestedCost = $rowConversion && (float) $rowConversion->purchase_price > 0
                            ? (float) $rowConversion->purchase_price
                            : (float) ($selectedProduct?->buying_price ?? 0) * $rowFactor;
                        $rowQuantity = is_numeric($row['quantity'] ?? null) ? (float) $row['quantity'] : 0;
                        $rowCost = is_numeric($row['unit_cost'] ?? null) ? (float) $row['unit_cost'] : 0;
                        $rowTotal = round($rowQuantity * $rowFactor * round($rowCost / $rowFactor, 2), 2);
                    @endphp
                    <div wire:key="opening-line-{{ $row['key'] }}" class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div class="mb-3 flex items-center justify-between"><strong>{{ __('application.product_dd3b86d1') }} {{ $index + 1 }}</strong>@if (count($lines) > 1)<button type="button" wire:click="removeLine({{ $index }})" class="text-sm font-bold text-red-600">{{ __('application.remove_e963907d') }}</button>@endif</div>
                        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                            <label class="block text-sm font-bold">{{ __('application.product_dd3b86d1') }}
                                <select wire:model.live="lines.{{ $index }}.product_id" wire:change="selectProduct({{ $index }}, $event.target.value)" class="erp-input mt-1"><option value="">{{ __('application.select_product_596d90e5') }}</option>@foreach ($products as $product)<option value="{{ $product->id }}">{{ $product->displayNameWithSize() }} / {{ $product->sku }}</option>@endforeach</select>
                                @error("lines.{$index}.product_id") <span class="erp-error">{{ $message }}</span> @enderror
                            </label>
                            <label class="block text-sm font-bold">{{ __('application.unit_f6b935ab') }}
                                <select wire:model.live="lines.{{ $index }}.product_unit_conversion_id" wire:change="selectUnit({{ $index }}, $event.target.value)" class="erp-input mt-1" @disabled(! $selectedProduct)><option value="">{{ $selectedProduct?->unit?->name ?: __('application.select_a_product_first_04d808c1') }}</option>@foreach ($unitOptions as $option)<option value="{{ $option->id }}">{{ $option->unit?->name }} = {{ \App\Support\NumberFormatter::quantity($option->conversion_factor) }} {{ $selectedProduct?->unit?->short_name }}</option>@endforeach</select>
                                @error("lines.{$index}.product_unit_conversion_id") <span class="erp-error">{{ $message }}</span> @enderror
                            </label>
                            <div><x-form-input label="{{ __('application.quantity_44f6af69') }}" name="lines.{{ $index }}.quantity" type="number" step="0.0001" wire:model.live="lines.{{ $index }}.quantity" required />@error("lines.{$index}.quantity") <span class="erp-error">{{ $message }}</span> @enderror</div>
                            <div><x-form-input label="{{ __('application.unit_cost_tzs_904a1784') }}" name="lines.{{ $index }}.unit_cost" type="number" step="0.01" wire:model.live="lines.{{ $index }}.unit_cost" required />@error("lines.{$index}.unit_cost") <span class="erp-error">{{ $message }}</span> @enderror@if ($selectedProduct && $rowSuggestedCost > 0)<span class="text-xs text-slate-500">{{ __('application.current_buying_price_is_tzs_fa3ce8a7') }} {{ \App\Support\NumberFormatter::money($rowSuggestedCost) }}{{ __('application.review_the_historical_cost_of_this_product_0232bc39') }}</span>@endif</div>
                            @if ($selectedProduct?->tracks_batch || $selectedProduct?->tracks_expiry)
                                <div><x-form-input label="{{ __('application.batch_number_86b217fd') }}" name="lines.{{ $index }}.batch_number" wire:model="lines.{{ $index }}.batch_number" :required="$selectedProduct->tracks_batch" />@error("lines.{$index}.batch_number") <span class="erp-error">{{ $message }}</span> @enderror</div>
                            @endif
                            @if ($selectedProduct?->tracks_expiry)
                                <div><x-form-input label="{{ __('application.end_date_84b14781') }}" name="lines.{{ $index }}.expiry_date" type="date" wire:model="lines.{{ $index }}.expiry_date" required />@error("lines.{$index}.expiry_date") <span class="erp-error">{{ $message }}</span> @enderror</div>
                            @endif
                            <div class="xl:col-span-2"><x-form-input label="{{ __('application.notes_70440046') }}" name="lines.{{ $index }}.notes" wire:model="lines.{{ $index }}.notes" /></div>
                        </div>
                        <p class="mt-3 text-xs text-slate-500">{{ __('application.total_value_tzs_f8b8f60e') }} {{ \App\Support\NumberFormatter::money($rowTotal) }} {{ __('application.base_quantity_7ec8d7e3') }} {{ \App\Support\NumberFormatter::quantity($rowQuantity * $rowFactor) }} {{ $selectedProduct?->unit?->short_name }}</p>
                    </div>
                @endforeach
            </div>
            <button type="button" wire:click="addLine" class="mt-4 rounded-lg border border-cyan-300 px-4 py-2 text-sm font-bold text-cyan-700">{{ __('application.add_product_ee611e53') }}</button>
            @error('lines') <p class="erp-error">{{ $message }}</p> @enderror
        </x-card>
        <x-card title="{{ __('application.summary_12b71c3e') }}">
            <div class="grid gap-4 sm:grid-cols-3"><div><p class="text-sm text-slate-500">{{ __('application.total_products_c48f2bf5') }}</p><p class="text-xl font-black">{{ $productIds->count() }}</p></div><div><p class="text-sm text-slate-500">{{ __('application.total_base_quantity_050406f0') }}</p><p class="text-xl font-black">{{ \App\Support\NumberFormatter::quantity($totalBase) }}</p><p class="text-xs text-slate-500">{{ __('application.product_units_may_differ_f03b58c7') }}</p></div><div><p class="text-sm text-slate-500">{{ __('application.total_opening_stock_value_c9957ec1') }}</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($totalValue) }}</p></div></div>
        </x-card>
        <button class="rounded-xl bg-cyan-600 px-6 py-3 font-black text-white" wire:loading.attr="disabled" wire:confirm="{{ __('application.save_this_opening_stock_you_will_not_be_able_to_change_it_after_saving_152220e3') }}">{{ __('application.save_opening_stock_19b75aca') }}</button>
    </form>
</div>
