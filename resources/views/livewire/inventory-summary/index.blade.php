<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\StockLocation;
use App\Services\InventoryService;
use App\Support\AuthorizationScope;
use App\Support\InventorySettings;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['search' => '', 'categoryFilter' => '', 'statusFilter' => '']);

mount(function () {
    $this->search = request('search', $this->search);
    $this->categoryFilter = request('categoryFilter', $this->categoryFilter);
    $this->statusFilter = request('statusFilter', $this->statusFilter);
});

?>

<div>
    <x-page-header title="{{ __('application.inventory_summary_d629dc53') }}" description="{{ __('application.stock_across_authorised_locations_with_a_breakdown_for_each_location_05fe55c2') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Inventory Summary' => null]" />

    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-4">
            <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5 md:col-span-2" placeholder="{{ __('application.search_products_b73f756b') }}">
            <select wire:model.live="categoryFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ __('application.all_categories_060be00f') }}</option>
                @foreach (Category::orderBy('name')->get() as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ __('application.all_stock_statuses_6623c5a6') }}</option>
                <option value="in_stock">{{ __('application.in_stock_3f1356ad') }}</option>
                <option value="low_stock">{{ __('application.low_stock_a6e1fef1') }}</option>
                <option value="out_of_stock">{{ __('application.out_of_stock_8b78c7ae') }}</option>
            </select>
        </div>

        @php
            $branchId = InventorySettings::branchId();
            $locations = StockLocation::query()
                ->where(fn ($query) => $query->where('branch_id', $branchId)->orWhereNull('branch_id'))
                ->whereIn('id', AuthorizationScope::stockLocationIds(auth()->user(), 'can_view'))
                ->where('status', 'active')->where('is_active', true)
                ->orderBy('name')->get();
            if (! InventorySettings::warehouseEnabled()) {
                $canonicalId = app(InventoryService::class)->getDispensingLocation($branchId)->id;
                $locations = $locations->where('id', $canonicalId);
            }
            $inventory = app(InventoryService::class);
            $rows = Product::with(['category', 'measurementType', 'unit', 'size'])
                ->when($search, fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")))
                ->when($categoryFilter, fn ($query) => $query->where('category_id', $categoryFilter))
                ->orderBy('name')->get()
                ->map(function (Product $product) use ($locations, $inventory, $branchId) {
                    $balances = $locations->map(function (StockLocation $location) use ($product, $inventory, $branchId) {
                        $warehouse = $location->is_warehouse || $location->type === 'warehouse' || $location->code === 'MAIN-STORE';
                        return [
                            'id' => $location->id,
                            'name' => $location->name,
                            'quantity' => $inventory->getProductStock($product->id, $location->id, $branchId),
                            'selling' => ! $warehouse && $location->can_sell && $location->is_sellable,
                        ];
                    });
                    $sellingQty = $balances->where('selling', true)->sum('quantity');
                    $totalQty = $balances->sum('quantity');
                    $warehouseQty = $totalQty - $sellingQty;
                    $status = $totalQty <= 0 ? 'out_of_stock' : ($totalQty <= (float) $product->reorder_level ? 'low_stock' : 'in_stock');
                    return compact('product', 'balances', 'warehouseQty', 'sellingQty', 'totalQty', 'status');
                })
                ->when($statusFilter, fn ($items) => $items->where('status', $statusFilter))
                ->values();
        @endphp

        <x-table :headers="[__('terminology.product_locations_7a0636cb'), __('application.measurement_type_bbc2cb53'), __('application.size_b7152342'), __('application.category_a3c686e7'), __('application.unit_f6b935ab'), __('terminology.warehouse_other_qty_878f03de'), __('terminology.total_selling_qty_2f59f228'), __('terminology.total_stock_21553401'), __('application.reorder_33d9978c'), __('application.status_bae7d5be')]">
            @forelse ($rows as $row)
                @php $product = $row['product']; @endphp
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                    <td class="px-4 py-3 font-black">
                        <details>
                            <summary class="cursor-pointer">{{ $product->displayNameWithSize() }}</summary>
                            <ul class="mt-2 space-y-1 text-sm font-normal">
                                @foreach ($row['balances'] as $balance)
                                    <li>{{ $balance['name'] }}: {{ \App\Support\NumberFormatter::quantity($balance['quantity']) }}</li>
                                @endforeach
                            </ul>
                        </details>
                    </td>
                    <td class="px-4 py-3">{{ $product->measurementType?->name ?? str($product->measurementCode())->title() }}</td>
                    <td class="px-4 py-3">{{ $product->sizeLabel() ?: '—' }}</td>
                    <td class="px-4 py-3">{{ $product->category?->name }}</td>
                    <td class="px-4 py-3">{{ $product->unit?->short_name }}</td>
                    <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($row['warehouseQty']) }}</td>
                    <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($row['sellingQty']) }}</td>
                    <td class="px-4 py-3 font-black">{{ \App\Support\NumberFormatter::quantity($row['totalQty']) }}</td>
                    <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($product->reorder_level) }}</td>
                    <td class="px-4 py-3"><span class="{{ $row['status'] === 'in_stock' ? 'badge-success' : ($row['status'] === 'low_stock' ? 'badge-warning' : 'rounded-full bg-red-50 px-2.5 py-1 text-xs font-black text-red-700') }}">{{ \App\Support\UiText::label($row['status']) }}</span></td>
                </tr>
            @empty
                <tr><td colspan="10" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_inventory_summary_records_found_ca4b0a8a') }}</td></tr>
            @endforelse
        </x-table>
    </x-card>
</div>
