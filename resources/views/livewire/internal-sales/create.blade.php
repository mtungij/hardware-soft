<?php

use App\Models\Branch;
use App\Models\InternalSale;
use App\Models\Product;
use App\Models\StockLocation;
use App\Services\InternalSaleService;
use App\Services\LocationPriceService;
use App\Support\AuthorizationScope;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state([
    'branch_id' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''), 'from_location_id' => '', 'to_location_id' => '',
    'internal_sale_number' => '', 'sale_date' => '', 'notes' => '', 'editingSaleId' => null,
    'items' => [['selection' => '', 'quantity' => '1', 'internal_unit_price' => '', 'price_source' => '', 'manual_override' => false]],
]);

mount(function ($internalSale = null) {
    $this->branch_id = (string) auth()->user()->branch_id;
    $this->sale_date = today()->toDateString();
    $this->internal_sale_number = 'IS-'.now()->format('Ymd-His').'-'.strtoupper(str()->random(4));

    $sale = $internalSale ?? request()->route('internalSale');

    if ($sale && ! $sale instanceof InternalSale) {
        $sale = InternalSale::query()
            ->where('company_id', auth()->user()->company_id)
            ->find($sale);
    }

    if (! $sale instanceof InternalSale) {
        return;
    }
    abort_unless((int) $sale->company_id === (int) auth()->user()->company_id
        && $sale->status === 'draft'
        && AuthorizationScope::canAccessStockLocation(auth()->user(), (int) $sale->from_location_id)
        && AuthorizationScope::canAccessStockLocation(auth()->user(), (int) $sale->to_location_id), 403);
    $this->editingSaleId = $sale->id;
    $this->branch_id = (string) $sale->branch_id;
    $this->from_location_id = (string) $sale->from_location_id;
    $this->to_location_id = (string) $sale->to_location_id;
    $this->internal_sale_number = $sale->internal_sale_number;
    $this->sale_date = $sale->sale_date->toDateString();
    $this->notes = $sale->notes ?? '';
    $this->items = $sale->items()->with('product.unitConversions')->get()->map(function ($item) use ($sale): array {
        $conversion = $item->product_unit_conversion_id
            ? $item->product->unitConversions->firstWhere('id', $item->product_unit_conversion_id) : null;
        $source = app(LocationPriceService::class)->internalPriceFor($item->product, $sale->fromLocation, $conversion);
        $manual = $source['price'] === null || abs($source['price'] - (float) $item->internal_unit_price) > 0.005;

        return [
            'selection' => $item->product_id.':'.($item->product_unit_conversion_id ?? ''),
            'quantity' => (string) $item->transaction_quantity,
            'internal_unit_price' => number_format((float) $item->internal_unit_price, 2, '.', ''),
            'price_source' => $manual ? 'Manual Override' : $source['source'],
            'manual_override' => $manual,
        ];
    })->all();
});

$refreshItemPrice = function (int $index): void {
    if (! isset($this->items[$index]) || ($this->items[$index]['manual_override'] ?? false)) {
        return;
    }
    [$productId, $conversionId] = array_pad(explode(':', (string) ($this->items[$index]['selection'] ?? ''), 2), 2, '');
    $product = Product::query()->with('unitConversions.unit')
        ->where('company_id', auth()->user()->company_id)->find($productId);
    if (! $product) {
        $this->items[$index]['internal_unit_price'] = '';
        $this->items[$index]['price_source'] = '';
        return;
    }
    $conversion = $conversionId ? $product->unitConversions->firstWhere('id', (int) $conversionId) : null;
    if ($conversionId && ! $conversion) {
        $this->items[$index]['internal_unit_price'] = '';
        $this->items[$index]['price_source'] = '';
        return;
    }
    $source = $this->branch_id
        ? app(InternalSaleService::class)->sourceLocations(auth()->user(), (int) $this->branch_id)
            ->firstWhere('id', (int) $this->from_location_id)
        : null;
    $resolved = app(LocationPriceService::class)->internalPriceFor($product, $source, $conversion);
    $this->items[$index]['internal_unit_price'] = $resolved['price'] === null
        ? '' : number_format($resolved['price'], 2, '.', '');
    $this->items[$index]['price_source'] = $resolved['source'] ?? 'No default selling price; authorized manual entry required';
};

$updatedBranchId = function (): void {
    $this->from_location_id = '';
    $this->to_location_id = '';
    foreach (array_keys($this->items) as $index) {
        $this->refreshItemPrice($index);
    }
};

$updatedFromLocationId = function (): void {
    foreach (array_keys($this->items) as $index) {
        $this->refreshItemPrice($index);
    }
};

$updatedItems = function ($value, string $key): void {
    [$index, $field] = array_pad(explode('.', $key, 2), 2, '');
    if (! is_numeric($index) || ! isset($this->items[(int) $index])) {
        return;
    }
    if ($field === 'selection') {
        $this->items[(int) $index]['manual_override'] = false;
        $this->refreshItemPrice((int) $index);
    } elseif ($field === 'internal_unit_price') {
        $this->items[(int) $index]['manual_override'] = true;
        $this->items[(int) $index]['price_source'] = 'Manual Override';
    }
};

$addItem = function (): void {
    $this->items[] = ['selection' => '', 'quantity' => '1', 'internal_unit_price' => '', 'price_source' => '', 'manual_override' => false];
};

$removeItem = function (int $index): void {
    unset($this->items[$index]);
    $this->items = array_values($this->items);
};

$resetItemPrice = function (int $index): void {
    if (! isset($this->items[$index])) {
        return;
    }
    $this->items[$index]['manual_override'] = false;
    $this->refreshItemPrice($index);
};

$loadPrices = function (): void {
    foreach (array_keys($this->items) as $index) {
        $this->refreshItemPrice($index);
    }
};

$save = function (InternalSaleService $service): void {
    $validated = $this->validate([
        'branch_id' => ['required', 'integer'],
        'from_location_id' => ['required', 'integer', 'different:to_location_id'],
        'to_location_id' => ['required', 'integer'],
        'internal_sale_number' => ['required', 'string', 'max:50'],
        'sale_date' => ['required', 'date'],
        'notes' => ['nullable', 'string', 'max:5000'],
        'items' => ['required', 'array', 'min:1'],
        'items.*.selection' => ['required', 'string'],
        'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        'items.*.internal_unit_price' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
    ]);
    $validated['items'] = collect($validated['items'])->map(function (array $row): array {
        [$productId, $conversionId] = array_pad(explode(':', $row['selection'], 2), 2, '');
        return [
            'product_id' => (int) $productId,
            'product_unit_conversion_id' => $conversionId === '' ? null : (int) $conversionId,
            'quantity' => $row['quantity'],
            'internal_unit_price' => $row['internal_unit_price'],
        ];
    })->all();
    $existing = $this->editingSaleId
        ? InternalSale::query()->where('company_id', auth()->user()->company_id)->findOrFail($this->editingSaleId)
        : null;
    $sale = $service->saveDraft($validated, auth()->user(), $existing);
    $this->redirectRoute('internal-sales.show', $sale, navigate: true);
};

$completeSale = function (InternalSaleService $service): void {
    $validated = $this->validate([
        'branch_id' => ['required', 'integer'],
        'from_location_id' => ['required', 'integer', 'different:to_location_id'],
        'to_location_id' => ['required', 'integer'],
        'internal_sale_number' => ['required', 'string', 'max:50'],
        'sale_date' => ['required', 'date'],
        'notes' => ['nullable', 'string', 'max:5000'],
        'items' => ['required', 'array', 'min:1'],
        'items.*.selection' => ['required', 'string'],
        'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        'items.*.internal_unit_price' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
    ]);

    $validated['items'] = collect($validated['items'])->map(function (array $row): array {
        [$productId, $conversionId] = array_pad(explode(':', $row['selection'], 2), 2, '');

        return [
            'product_id' => (int) $productId,
            'product_unit_conversion_id' => $conversionId === '' ? null : (int) $conversionId,
            'quantity' => $row['quantity'],
            'internal_unit_price' => $row['internal_unit_price'],
        ];
    })->all();

    $existing = $this->editingSaleId
        ? InternalSale::query()
            ->where('company_id', auth()->user()->company_id)
            ->findOrFail($this->editingSaleId)
        : null;

    $sale = $service->saveDraft($validated, auth()->user(), $existing);
    $sale = $service->complete($sale, auth()->user());

    session()->flash('success', __('application.internal_sale_completed_successfully_bdb17c3f'));

    $this->redirectRoute('internal-sales.show', $sale, navigate: true);
};

?>

<div>
    <x-page-header :title="$editingSaleId ? __('terminology.edit_internal_sale_550e8790') : __('application.create_internal_sale_601715df')" description="{{ __('application.move_stock_at_an_internal_price_while_retaining_company_inventory_cost_ccb712d4') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Internal Sales' => route('internal-sales.index'), ($editingSaleId ? 'Edit' : 'Create') => null]" />
    @php
        $user = auth()->user();
        $service = app(InternalSaleService::class);
        $branches = Branch::query()->where('company_id', $user->company_id)
            ->when(AuthorizationScope::scopeFor($user, 'stock_scope', AuthorizationScope::ASSIGNED_LOCATIONS) !== AuthorizationScope::COMPANY,
                fn ($query) => $query->whereKey($user->branch_id))->orderBy('name')->get();
        $sources = $branch_id ? $service->sourceLocations($user, (int) $branch_id) : collect();
        $destinations = $branch_id ? $service->destinationLocations($user, (int) $branch_id) : collect();
        $products = Product::query()->with(['unit', 'unitConversions.unit'])->where('company_id', $user->company_id)->where('status', 'active')->orderBy('name')->get();
    @endphp
    <form wire:submit="save">
        <x-card>
            <div class="grid gap-4 md:grid-cols-3">
                <label class="text-sm font-bold">{{ __('application.number_b7baa1d4') }}<input wire:model="internal_sale_number" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-navy-950">@error('internal_sale_number')<span class="text-red-600">{{ $message }}</span>@enderror</label>
                <label class="text-sm font-bold">{{ __('application.date_eb9a4bc1') }}<input wire:model="sale_date" type="date" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-navy-950">@error('sale_date')<span class="text-red-600">{{ $message }}</span>@enderror</label>
                <label class="text-sm font-bold">{{ __('application.branch_1627510b') }}<select wire:model.live="branch_id" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())><option value="">{{ __('application.choose_branch_144ee159') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>@error('branch_id')<span class="text-red-600">{{ $message }}</span>@enderror</label>
                <label class="text-sm font-bold">{{ __('application.source_6da13add') }}<select wire:model.live="from_location_id" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-navy-950"><option value="">{{ __('application.choose_source_31bf0cd8') }}</option>@foreach($sources as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select>@error('from_location_id')<span class="text-red-600">{{ $message }}</span>@enderror</label>
                <label class="text-sm font-bold">{{ __('application.destination_d4271349') }}<select wire:model="to_location_id" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-navy-950"><option value="">{{ __('application.choose_destination_a311df08') }}</option>@foreach($destinations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select>@error('to_location_id')<span class="text-red-600">{{ $message }}</span>@enderror</label>
                <label class="text-sm font-bold">{{ __('application.notes_70440046') }}<input wire:model="notes" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-navy-950"></label>
            </div>
        </x-card>
        <x-card class="mt-4">
            <div class="mb-3 flex items-center justify-between"><h3 class="font-bold">{{ __('application.items_44d25b5d') }}</h3><div class="flex gap-2"><button type="button" wire:click="loadPrices" class="rounded-lg border px-3 py-2 text-sm font-bold">{{ __('application.load_location_prices_14179e5a') }}</button><button type="button" wire:click="addItem" class="rounded-lg border px-3 py-2 text-sm font-bold">{{ __('application.add_item_5bcf4db4') }}</button></div></div>
            @error('items')<p class="text-red-600">{{ $message }}</p>@enderror
            @foreach($items as $index => $row)
                <div wire:key="internal-item-{{ $index }}" class="mb-3 grid gap-3 md:grid-cols-4">
                    <label class="text-sm">{{ __('application.product_unit_8b22f066') }}<select wire:model.live="items.{{ $index }}.selection" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-navy-950"><option value="">{{ __('application.choose_product_23d8d26d') }}</option>@foreach($products as $product)<option value="{{ $product->id }}:">{{ $product->displayName() }} ({{ $product->unit?->short_name }})</option>@foreach($product->unitConversions->filter(fn ($conversion) => $conversion->active && $conversion->can_sell && $conversion->unit?->status === 'active') as $conversion)<option value="{{ $product->id }}:{{ $conversion->id }}">{{ $product->displayName() }} ({{ $conversion->unit?->short_name }})</option>@endforeach @endforeach</select>@error("items.{$index}.selection")<span class="text-red-600">{{ $message }}</span>@enderror</label>
                    <label class="text-sm">{{ __('application.quantity_44f6af69') }}<input wire:model="items.{{ $index }}.quantity" type="number" step="0.0001" min="0.0001" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-navy-950">@error("items.{$index}.quantity")<span class="text-red-600">{{ $message }}</span>@enderror</label>
                    <label class="text-sm">{{ __('application.internal_unit_price_98164503') }}<input wire:model.live.debounce.300ms="items.{{ $index }}.internal_unit_price" type="number" step="0.01" min="0" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-navy-950">@error("items.{$index}.internal_unit_price")<span class="text-red-600">{{ $message }}</span>@enderror</label>
                    <div class="self-end flex gap-2"><button type="button" wire:click="resetItemPrice({{ $index }})" class="rounded-lg border px-3 py-2 text-sm">{{ __('application.use_suggested_price_eedd55e5') }}</button><button type="button" wire:click="removeItem({{ $index }})" class="rounded-lg border px-3 py-2 text-sm">{{ __('application.remove_e963907d') }}</button></div>
                    <p class="md:col-span-4 text-xs text-slate-500">{{ __('application.price_source_ecb2a5f2') }} {{ $row['price_source'] ?: __('terminology.select_a_product_df0fb482') }}</p>
                </div>
            @endforeach
            <p class="text-xs text-slate-500">{{ __('application.manual_prices_require_override_permission_load_location_prices_keeps_manual_22bdb18e') }}</p>
        </x-card>
        <div class="mt-4 flex flex-wrap gap-3">
            <button type="submit" class="rounded-lg border px-4 py-2 font-bold">
                {{ $editingSaleId ? __('terminology.update_draft_9b53e388') : __('application.save_draft_cc1316dd') }}
            </button>

            @can('internal_sales.complete')
                <button type="button" wire:click="completeSale" wire:loading.attr="disabled" class="rounded-lg bg-build-orange px-4 py-2 font-bold text-white">
                    {{ __('application.complete_internal_sale_656b3292') }}
                </button>
            @endcan

            <a href="{{ route('internal-sales.index') }}" wire:navigate class="rounded-lg border px-4 py-2">
                {{ __('application.cancel_77dfd213') }}
            </a>
        </div>
    </form>
</div>
