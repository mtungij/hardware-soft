<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state([
    'search' => '',
    'statusFilter' => '',
    'branchFilter' => '',
    'categoryFilter' => '',
]);

$canManage = fn () => auth()->user()->hasAnyRole(['Super Admin', 'Admin']);

$toggleStatus = function (int $productId) {
    abort_unless($this->canManage(), 403);

    $product = Product::findOrFail($productId);
    $product->update(['status' => $product->status === 'active' ? 'inactive' : 'active']);

    session()->flash('success', 'Product status updated.');
};

$deleteProduct = function (int $productId) {
    abort_unless($this->canManage(), 403);

    Product::findOrFail($productId)->delete();

    session()->flash('success', 'Product deleted.');
};

?>

<div>
    <x-page-header
        title="Products"
        description="Product master data only. Stock quantities will come from stock movements in Phase 3 and Phase 4."
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Products' => null]"
    >
        @if ($this->canManage())
            <a href="{{ route('products.create') }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white shadow-lg shadow-orange-500/25">Create Product</a>
        @endif
    </x-page-header>

    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-5">
            <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5 md:col-span-2" placeholder="Search products, SKU, barcode...">
            <select wire:model.live="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
            <select wire:model.live="branchFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All branches</option>
                @foreach (Branch::orderBy('name')->get() as $branch)
                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="categoryFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">All categories</option>
                @foreach (Category::orderBy('name')->get() as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
        </div>

        @php
            $products = Product::query()
                ->with(['branch', 'category', 'unit'])
                ->when($search, fn ($query) => $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")))
                ->when($statusFilter, fn ($query) => $query->where('status', $statusFilter))
                ->when($branchFilter, fn ($query) => $query->where('branch_id', $branchFilter))
                ->when($categoryFilter, fn ($query) => $query->where('category_id', $categoryFilter))
                ->latest()
                ->paginate(10);
        @endphp

        <x-table :headers="['Product', 'SKU', 'Barcode', 'Category', 'Unit', 'Buying', 'Selling', 'Reorder', 'Status', 'Actions']">
            @forelse ($products as $product)
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                    <td class="px-4 py-3">
                        <div class="flex min-w-56 items-center gap-3">
                            <img class="h-11 w-11 rounded-lg object-cover" src="{{ $product->image ? asset('storage/'.$product->image) : 'https://ui-avatars.com/api/?name='.urlencode($product->name).'&background=f97316&color=fff' }}" alt="{{ $product->name }}">
                            <div>
                                <p class="font-black">{{ $product->name }}</p>
                                <p class="text-xs text-slate-500">{{ $product->brand ?? 'No brand' }} {{ $product->model_size ? '/ '.$product->model_size : '' }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 font-mono text-xs">{{ $product->sku }}</td>
                    <td class="px-4 py-3 font-mono text-xs">{{ $product->barcode ?? '-' }}</td>
                    <td class="px-4 py-3">{{ $product->category?->name }}</td>
                    <td class="px-4 py-3">{{ $product->unit?->short_name }}</td>
                    <td class="px-4 py-3">TZS {{ number_format((float) $product->buying_price, 2) }}</td>
                    <td class="px-4 py-3 font-bold">TZS {{ number_format((float) $product->selling_price, 2) }}</td>
                    <td class="px-4 py-3">{{ number_format((float) $product->reorder_level, 2) }}</td>
                    <td class="px-4 py-3"><span class="{{ $product->status === 'active' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($product->status) }}</span></td>
                    <td class="px-4 py-3">
                        @if ($this->canManage())
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ route('products.edit', $product) }}" wire:navigate class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">Edit</a>
                                <button wire:click="toggleStatus({{ $product->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ $product->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                                <button wire:click="deleteProduct({{ $product->id }})" wire:confirm="Delete this product?" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white">Delete</button>
                            </div>
                        @else
                            <span class="text-xs text-slate-500">View only</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="px-4 py-8 text-center text-slate-500">No products found.</td></tr>
            @endforelse
        </x-table>

        <div class="mt-4">{{ $products->links() }}</div>
    </x-card>
</div>
