<?php

use App\Models\Branch;
use App\Models\LocationPrice;
use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\StockLocation;
use App\Services\LocationPriceService;
use App\Support\AuthorizationScope;
use Illuminate\Validation\ValidationException;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['branch_id' => '', 'stock_location_id' => '', 'search' => '', 'rows' => []]);

$branches = function () {
    $user = auth()->user();
    $query = Branch::query()->where('company_id', $user->company_id)->where('status', 'active');
    if (AuthorizationScope::scopeFor($user, 'stock_scope', AuthorizationScope::ASSIGNED_LOCATIONS) !== AuthorizationScope::COMPANY) {
        $query->whereKey($user->branch_id);
    }

    return $query->orderBy('name')->get();
};

$locations = function () {
    if (! $this->branch_id || ! $this->branches()->contains('id', (int) $this->branch_id)) {
        return collect();
    }

    return AuthorizationScope::stockLocationsForBranch(auth()->user(), 'can_view', (int) $this->branch_id);
};

$loadRows = function (): void {
    $this->rows = [];
    $location = $this->locations()->firstWhere('id', (int) $this->stock_location_id);
    if (! $location) {
        return;
    }

    $products = Product::query()->with(['unit', 'unitConversions.unit'])
        ->where('company_id', auth()->user()->company_id)
        ->where('status', 'active')
        ->when(filled($this->search), fn ($query) => $query->where(fn ($q) => $q
            ->where('name', 'like', '%'.$this->search.'%')
            ->orWhere('sku', 'like', '%'.$this->search.'%')))
        ->orderBy('name')->limit(40)->get();
    $prices = LocationPrice::query()
        ->where('company_id', auth()->user()->company_id)
        ->where('stock_location_id', $location->id)
        ->whereIn('product_id', $products->modelKeys())
        ->get()->keyBy(fn (LocationPrice $price) => $price->product_id.':'.$price->unit_key);

    foreach ($products as $product) {
        $units = collect([null])->concat($product->unitConversions
            ->filter(fn (ProductUnitConversion $conversion) => $conversion->active && $conversion->can_sell && $conversion->unit?->status === 'active'));
        foreach ($units as $conversion) {
            $key = $product->id.':'.($conversion?->id ?? 0);
            $price = $prices->get($key);
            $this->rows[$key] = [
                'product_id' => $product->id,
                'conversion_id' => $conversion?->id,
                'product_name' => $product->displayNameWithSize(),
                'sku' => $product->sku,
                'unit' => $conversion?->unit?->short_name ?? $product->unit?->short_name,
                'default_retail' => $conversion?->retail_price ?? $product->selling_price,
                'default_wholesale' => $conversion?->wholesale_price ?? $product->wholesale_price,
                'retail_price' => $price?->retail_price ?? '',
                'wholesale_price' => $price?->wholesale_price ?? '',
                'internal_sale_price' => $price?->internal_sale_price ?? '',
                'is_active' => $price?->is_active ?? true,
            ];
        }
    }
};

mount(function () {
    abort_unless(auth()->user()?->can('location_prices.view'), 403);
    $this->branch_id = (string) ($this->branches()->firstWhere('id', auth()->user()->branch_id)?->id ?? $this->branches()->first()?->id ?? '');
    $this->stock_location_id = (string) ($this->locations()->first()?->id ?? '');
    $this->loadRows();
});

$updatedBranchId = function (): void {
    $this->stock_location_id = (string) ($this->locations()->first()?->id ?? '');
    $this->loadRows();
};

$updatedStockLocationId = fn () => $this->loadRows();
$updatedSearch = fn () => $this->loadRows();

$savePrices = function (LocationPriceService $service): void {
    abort_unless(auth()->user()?->can('location_prices.manage'), 403);
    $location = $this->locations()->firstWhere('id', (int) $this->stock_location_id);
    if (! $location) {
        throw ValidationException::withMessages(['stock_location_id' => 'Select an authorized location.']);
    }
    $this->validate([
        'rows' => ['array'],
        'rows.*.product_id' => ['required', 'integer'],
        'rows.*.conversion_id' => ['nullable', 'integer'],
        'rows.*.retail_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        'rows.*.wholesale_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        'rows.*.internal_sale_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        'rows.*.is_active' => ['boolean'],
    ]);

    foreach ($this->rows as $index => $row) {
        $product = Product::query()->where('company_id', auth()->user()->company_id)->findOrFail($row['product_id']);
        $conversion = filled($row['conversion_id'] ?? null)
            ? ProductUnitConversion::query()->where('company_id', auth()->user()->company_id)
                ->where('product_id', $product->id)->findOrFail($row['conversion_id'])
            : null;
        $values = collect(['retail_price', 'wholesale_price', 'internal_sale_price'])
            ->mapWithKeys(fn ($field) => [$field => filled($row[$field] ?? null) ? $row[$field] : null])->all();
        $key = ['company_id' => $product->company_id, 'product_id' => $product->id,
            'stock_location_id' => $location->id, 'unit_key' => $conversion?->id ?? 0];
        if (collect($values)->every(fn ($value) => $value === null) && (bool) ($row['is_active'] ?? true)) {
            LocationPrice::query()->where($key)->delete();

            continue;
        }
        $service->savePrices($product, $location, $conversion, [
            ...$values, 'is_active' => (bool) ($row['is_active'] ?? true),
        ]);
    }
    session()->flash(
        'success',
        'Location prices for '.$location->name.' saved successfully.'
    );
    $this->loadRows();
};

?>

<div>
    <x-page-header title="Location Pricing" description="Set selling and internal sale prices for each stock location." :breadcrumbs="['Dashboard' => route('dashboard'), 'Location Pricing' => null]" />

    @if (session('success'))
        <div class="mb-4 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700">
            {{ session('success') }}
        </div>
    @endif

    <x-card>
        <div class="grid gap-3 md:grid-cols-3">
            <label class="text-sm font-bold">Branch
                <select wire:model.live="branch_id" class="mt-1 w-full rounded-lg border border-slate-200 bg-white p-2 dark:bg-navy-950">
                    @foreach ($this->branches() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach
                </select>
            </label>
            <label class="text-sm font-bold">Stock Location
                <select wire:model.live="stock_location_id" class="mt-1 w-full rounded-lg border border-slate-200 bg-white p-2 dark:bg-navy-950">
                    @foreach ($this->locations() as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach
                </select>
                @error('stock_location_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </label>
            <label class="text-sm font-bold">Search Product or SKU
                <input wire:model.live.debounce.300ms="search" class="mt-1 w-full rounded-lg border border-slate-200 p-2 dark:bg-navy-950">
            </label>
        </div>
        <p class="mt-3 text-xs text-slate-500">Blank Internal Sale Price uses the Product or Unit default selling price. Set a value here only when this location should use a different internal selling price. Showing up to 40 products.</p>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr class="border-b text-left text-xs uppercase text-slate-500">
                    <th class="p-2">Product</th><th class="p-2">Unit</th><th class="p-2">Default Retail</th><th class="p-2">Location Retail</th><th class="p-2">Default Wholesale</th><th class="p-2">Location Wholesale</th><th class="p-2">Internal Sale Price</th><th class="p-2">Active</th>
                </tr></thead>
                <tbody>
                    @forelse ($rows as $rowKey => $row)
                        <tr wire:key="location-price-{{ $row['product_id'] }}-{{ $row['conversion_id'] ?? 0 }}" class="border-b border-slate-100 dark:border-slate-800">
                            <td class="p-2 font-bold">{{ $row['product_name'] }}<span class="block text-xs font-normal text-slate-500">{{ $row['sku'] }}</span></td>
                            <td class="p-2">{{ $row['unit'] }}</td>
                            <td class="p-2">TZS {{ \App\Support\NumberFormatter::money($row['default_retail']) }}</td>
                            <td class="p-2"><input wire:model="rows.{{ $rowKey }}.retail_price" type="number" step="0.01" min="0" @disabled(! auth()->user()->can('location_prices.manage')) class="w-32 rounded-lg border border-slate-200 p-2 dark:bg-navy-950">@error("rows.{$rowKey}.retail_price") <span class="block text-xs text-red-600">{{ $message }}</span> @enderror</td>
                            <td class="p-2">TZS {{ \App\Support\NumberFormatter::money($row['default_wholesale']) }}</td>
                            <td class="p-2"><input wire:model="rows.{{ $rowKey }}.wholesale_price" type="number" step="0.01" min="0" @disabled(! auth()->user()->can('location_prices.manage')) class="w-32 rounded-lg border border-slate-200 p-2 dark:bg-navy-950">@error("rows.{$rowKey}.wholesale_price") <span class="block text-xs text-red-600">{{ $message }}</span> @enderror</td>
                            <td class="p-2"><input wire:model="rows.{{ $rowKey }}.internal_sale_price" type="number" step="0.01" min="0" @disabled(! auth()->user()->can('location_prices.manage')) class="w-32 rounded-lg border border-slate-200 p-2 dark:bg-navy-950">@error("rows.{$rowKey}.internal_sale_price") <span class="block text-xs text-red-600">{{ $message }}</span> @enderror</td>
                            <td class="p-2"><input wire:model="rows.{{ $rowKey }}.is_active" type="checkbox" @disabled(! auth()->user()->can('location_prices.manage'))></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="p-5 text-center text-slate-500">No products or authorized location found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @can('location_prices.manage')
            <button type="button" wire:click="savePrices" class="mt-4 rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Save Location Prices</button>
        @endcan
    </x-card>
</div>
