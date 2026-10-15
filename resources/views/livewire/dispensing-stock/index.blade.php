<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\StockLocation;
use App\Services\InventoryService;
use App\Support\AuthorizationScope;
use App\Support\InventorySettings;

use function Livewire\Volt\layout;
use function Livewire\Volt\state;

layout('layouts.app');

state(['search' => '', 'categoryFilter' => '', 'statusFilter' => '', 'locationFilter' => '']);

?>

<div>
    <x-page-header title="{{ __('application.dispensing_stock_80a461ef') }}" description="{{ __('application.stock_at_each_authorised_selling_location_53000705') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Dispensing Stock' => null]" />

    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-4">
            <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="{{ __('application.search_products_b73f756b') }}">
            <select wire:model.live="categoryFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ __('application.all_categories_060be00f') }}</option>
                @foreach (Category::orderBy('name')->get() as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ __('application.all_stock_statuses_6623c5a6') }}</option>
                <option value="in_stock">{{ __('application.in_stock_97d82654') }}</option>
                <option value="low_stock">{{ __('application.low_stock_023d1181') }}</option>
                <option value="out_of_stock">{{ __('application.out_of_stock_bc67a8ae') }}</option>
            </select>
            @php
                $branchId = InventorySettings::branchId();
                $locations = StockLocation::query()
                    ->where(fn ($query) => $query->where('branch_id', $branchId)->orWhereNull('branch_id'))
                    ->whereIn('id', AuthorizationScope::stockLocationIds(auth()->user(), 'can_view'))
                    ->where('status', 'active')->where('is_active', true)
                    ->where('can_sell', true)->where('is_sellable', true)
                    ->where(fn ($query) => $query->where('type', 'dispensing')->orWhere('is_dispensing_location', true))
                    ->orderBy('name')->get();
            if (! InventorySettings::warehouseEnabled()) {
                $canonicalId = app(InventoryService::class)->getDispensingLocation($branchId)->id;
                $locations = $locations->where('id', $canonicalId);
            }
            @endphp
            <select wire:model.live="locationFilter" aria-label="{{ __('application.selling_location_49cf3629') }}" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ __('application.all_authorised_selling_locations_b6aefa5f') }}</option>
                @foreach ($locations as $location)
                    <option value="{{ $location->id }}">{{ $location->name }}</option>
                @endforeach
            </select>
        </div>

        @php
            $inventory = app(InventoryService::class);
            $products = Product::with(['category', 'unit', 'size'])
                ->when($search, fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")))
                ->when($categoryFilter, fn ($query) => $query->where('category_id', $categoryFilter))
                ->orderBy('name')->get();
            $rows = $products->flatMap(fn (Product $product) => $locations
                ->when($locationFilter, fn ($items) => $items->where('id', (int) $locationFilter))
                ->map(function (StockLocation $location) use ($product, $inventory, $branchId) {
                    $quantity = $inventory->getProductStock($product->id, $location->id, $branchId);
                    $status = $quantity <= 0 ? 'out_of_stock' : ($quantity <= (float) $product->reorder_level ? 'low_stock' : 'in_stock');
                    return compact('product', 'location', 'quantity', 'status');
                }))
                ->when($statusFilter, fn ($items) => $items->where('status', $statusFilter))
                ->values();
        @endphp

        <x-table :headers="[__('application.product_dd3b86d1'), __('application.sku_d59192b9'), __('application.location_d219c681'), __('application.category_a3c686e7'), __('application.unit_f6b935ab'), __('application.qty_1e5ff9e5'), __('application.reorder_33d9978c'), __('application.status_bae7d5be')]">
            @forelse ($rows as $row)
                @php $product = $row['product']; @endphp
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                    <td class="px-4 py-3 font-black">{{ $product->displayNameWithSize() }}</td>
                    <td class="px-4 py-3 font-mono text-xs">{{ $product->sku }}</td>
                    <td class="px-4 py-3">{{ $row['location']->name }}</td>
                    <td class="px-4 py-3">{{ $product->category?->name }}</td>
                    <td class="px-4 py-3">{{ $product->unit?->short_name }}</td>
                    <td class="px-4 py-3 font-black">{{ \App\Support\NumberFormatter::quantity($row['quantity']) }}</td>
                    <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($product->reorder_level) }}</td>
                    <td class="px-4 py-3"><span class="{{ $row['status'] === 'in_stock' ? 'badge-success' : ($row['status'] === 'low_stock' ? 'badge-warning' : 'rounded-full bg-red-50 px-2.5 py-1 text-xs font-black text-red-700') }}">{{ \App\Support\UiText::label($row['status']) }}</span></td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_selling_stock_found_bc729320') }}</td></tr>
            @endforelse
        </x-table>
    </x-card>
</div>
