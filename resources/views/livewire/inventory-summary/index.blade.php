<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockLocation;
use App\Services\InventoryService;

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
    <x-page-header title="Inventory Summary" description="Combined stock position by Main Store and Dispensing Area." :breadcrumbs="['Dashboard' => route('dashboard'), 'Inventory Summary' => null]" />

    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-4">
            <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5 md:col-span-2" placeholder="Search products...">
            <select wire:model.live="categoryFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All categories</option>
                @foreach (Category::orderBy('name')->get() as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All stock statuses</option>
                <option value="in_stock">In stock</option>
                <option value="low_stock">Low stock</option>
                <option value="out_of_stock">Out of stock</option>
            </select>
        </div>

        @php
            $branchId = auth()->user()->branch_id ?: Branch::where('code', 'MAIN')->value('id');
            $store = StockLocation::where('branch_id', $branchId)->where('type', 'store')->first();
            $dispensing = StockLocation::where('branch_id', $branchId)->where('type', 'dispensing')->first();
            $inventory = app(InventoryService::class);
            $rows = Product::with(['category', 'unit'])
                ->when($search, fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")))
                ->when($categoryFilter, fn ($query) => $query->where('category_id', $categoryFilter))
                ->orderBy('name')
                ->get()
                ->map(function ($product) use ($inventory, $store, $dispensing, $branchId) {
                    $storeQty = $store ? $inventory->getProductStock($product->id, $store->id, $branchId) : 0;
                    $dispensingQty = $dispensing ? $inventory->getProductStock($product->id, $dispensing->id, $branchId) : 0;
                    $totalQty = $storeQty + $dispensingQty;
                    $status = $totalQty <= 0 ? 'out_of_stock' : ($totalQty <= (float) $product->reorder_level ? 'low_stock' : 'in_stock');
                    return compact('product', 'storeQty', 'dispensingQty', 'totalQty', 'status');
                })
                ->when($statusFilter, fn ($rows) => $rows->filter(fn ($row) => $row['status'] === $statusFilter)->values());
        @endphp

        <x-table :headers="['Product', 'Category', 'Unit', 'Main Store Qty', 'Dispensing Qty', 'Total Stock', 'Reorder', 'Status']">
            @forelse ($rows as $row)
                @php $product = $row['product']; @endphp
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                    <td class="px-4 py-3 font-black">{{ $product->name }}</td>
                    <td class="px-4 py-3">{{ $product->category?->name }}</td>
                    <td class="px-4 py-3">{{ $product->unit?->short_name }}</td>
                    <td class="px-4 py-3">{{ number_format($row['storeQty'], 2) }}</td>
                    <td class="px-4 py-3">{{ number_format($row['dispensingQty'], 2) }}</td>
                    <td class="px-4 py-3 font-black">{{ number_format($row['totalQty'], 2) }}</td>
                    <td class="px-4 py-3">{{ number_format((float) $product->reorder_level, 2) }}</td>
                    <td class="px-4 py-3"><span class="{{ $row['status'] === 'in_stock' ? 'badge-success' : ($row['status'] === 'low_stock' ? 'badge-warning' : 'rounded-full bg-red-50 px-2.5 py-1 text-xs font-black text-red-700 dark:bg-red-500/15 dark:text-red-300') }}">{{ str($row['status'])->replace('_', ' ')->title() }}</span></td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-8 text-center text-slate-500">No inventory summary records found.</td></tr>
            @endforelse
        </x-table>
    </x-card>
</div>
