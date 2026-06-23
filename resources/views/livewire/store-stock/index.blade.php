<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Services\InventoryService;

use function Livewire\Volt\layout;
use function Livewire\Volt\state;

layout('layouts.app');

state(['search' => '', 'categoryFilter' => '', 'statusFilter' => '']);

?>

<div>
    <x-page-header title="Main Store Stock" description="Current warehouse stock calculated from stock movements." :breadcrumbs="['Dashboard' => route('dashboard'), 'Store Stock' => null]" />

    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-3">
            <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="Search products...">
            <select wire:model.live="categoryFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All categories</option>
                @foreach (Category::orderBy('name')->get() as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All stock statuses</option>
                <option value="in_stock">In Stock</option>
                <option value="low_stock">Low Stock</option>
                <option value="out_of_stock">Out of Stock</option>
            </select>
        </div>

        @php
            $branchId = auth()->user()->branch_id ?: Branch::where('code', 'MAIN')->value('id');
            $location = StockLocation::where('branch_id', $branchId)->where('type', 'store')->first();
            $inventory = app(InventoryService::class);
            $rows = Product::with(['category', 'unit'])
                ->when($search, fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")))
                ->when($categoryFilter, fn ($query) => $query->where('category_id', $categoryFilter))
                ->orderBy('name')
                ->get()
                ->map(function ($product) use ($inventory, $location, $branchId) {
                    $quantity = $location ? $inventory->getProductStock($product->id, $location->id, $branchId) : 0;
                    $status = $quantity <= 0 ? 'out_of_stock' : ($quantity <= (float) $product->reorder_level ? 'low_stock' : 'in_stock');
                    return [
                        'product' => $product,
                        'quantity' => $quantity,
                        'average_cost' => $location ? $inventory->getAverageCost($product->id, $location->id, $branchId) : 0,
                        'last_received' => $location ? StockMovement::where('product_id', $product->id)->where('stock_location_id', $location->id)->where('movement_type', 'purchase_in')->latest('movement_date')->value('movement_date') : null,
                        'status' => $status,
                    ];
                })
                ->when($statusFilter, fn ($collection) => $collection->where('status', $statusFilter));
        @endphp

        <x-table :headers="['Product', 'SKU', 'Category', 'Unit', 'Store Qty', 'Avg Cost', 'Last Received', 'Reorder', 'Status']">
            @forelse ($rows as $row)
                @php $product = $row['product']; @endphp
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <img class="h-10 w-10 rounded-lg object-cover" src="{{ $product->image ? asset('storage/'.$product->image) : 'https://ui-avatars.com/api/?name='.urlencode($product->name).'&background=f97316&color=fff' }}" alt="{{ $product->name }}">
                            <span class="font-black">{{ $product->name }}</span>
                        </div>
                    </td>
                    <td class="px-4 py-3 font-mono text-xs">{{ $product->sku }}</td>
                    <td class="px-4 py-3">{{ $product->category?->name }}</td>
                    <td class="px-4 py-3">{{ $product->unit?->short_name }}</td>
                    <td class="px-4 py-3 font-black">{{ number_format($row['quantity'], 2) }}</td>
                    <td class="px-4 py-3">TZS {{ number_format($row['average_cost'], 2) }}</td>
                    <td class="px-4 py-3">{{ $row['last_received'] ? \Illuminate\Support\Carbon::parse($row['last_received'])->format('d M Y') : '-' }}</td>
                    <td class="px-4 py-3">{{ number_format((float) $product->reorder_level, 2) }}</td>
                    <td class="px-4 py-3">
                        <span class="{{ $row['status'] === 'in_stock' ? 'badge-success' : ($row['status'] === 'low_stock' ? 'badge-warning' : 'rounded-full bg-red-50 px-2.5 py-1 text-xs font-black text-red-700 dark:bg-red-500/15 dark:text-red-300') }}">{{ str($row['status'])->replace('_', ' ')->title() }}</span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-4 py-8 text-center text-slate-500">No stock records found.</td></tr>
            @endforelse
        </x-table>
    </x-card>
</div>
