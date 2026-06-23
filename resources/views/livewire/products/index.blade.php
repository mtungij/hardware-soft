<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Validation\Rule;
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
    'editing_product_id' => null,
    'deleting_product_id' => null,
    'branch_id' => '',
    'category_id' => '',
    'unit_id' => '',
    'name' => '',
    'sku' => '',
    'barcode' => '',
    'brand' => '',
    'model_size' => '',
    'image' => '',
    'buying_price' => '0',
    'selling_price' => '0',
    'wholesale_price' => '',
    'reorder_level' => '0',
    'taxable' => false,
    'status' => 'active',
]);

$canManage = fn () => auth()->user()->hasAnyRole(['Super Admin', 'Admin']);

$resetProductForm = function () {
    $this->reset([
        'editing_product_id',
        'branch_id',
        'category_id',
        'unit_id',
        'name',
        'sku',
        'barcode',
        'brand',
        'model_size',
        'image',
        'wholesale_price',
    ]);

    $this->buying_price = '0';
    $this->selling_price = '0';
    $this->reorder_level = '0';
    $this->taxable = false;
    $this->status = 'active';
    $this->resetErrorBag();
};

$openCreateProduct = function () {
    abort_unless($this->canManage(), 403);

    $this->resetProductForm();
    $this->dispatch('open-modal', 'product-form');
};

$openEditProduct = function (int $productId) {
    abort_unless($this->canManage(), 403);

    $product = Product::findOrFail($productId);

    $this->editing_product_id = $product->id;
    $this->branch_id = (string) $product->branch_id;
    $this->category_id = (string) $product->category_id;
    $this->unit_id = (string) $product->unit_id;
    $this->name = $product->name;
    $this->sku = $product->sku;
    $this->barcode = $product->barcode;
    $this->brand = $product->brand;
    $this->model_size = $product->model_size;
    $this->image = $product->image;
    $this->buying_price = (string) $product->buying_price;
    $this->selling_price = (string) $product->selling_price;
    $this->wholesale_price = $product->wholesale_price === null ? '' : (string) $product->wholesale_price;
    $this->reorder_level = (string) $product->reorder_level;
    $this->taxable = (bool) $product->taxable;
    $this->status = $product->status;
    $this->resetErrorBag();

    $this->dispatch('open-modal', 'product-form');
};

$saveProduct = function () {
    abort_unless($this->canManage(), 403);

    $validated = $this->validate([
        'branch_id' => ['nullable', 'exists:branches,id'],
        'category_id' => ['required', 'exists:categories,id'],
        'unit_id' => ['required', 'exists:units,id'],
        'name' => ['required', 'string', 'max:255'],
        'sku' => ['required', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($this->editing_product_id)],
        'barcode' => ['nullable', 'string', 'max:100', Rule::unique('products', 'barcode')->ignore($this->editing_product_id)],
        'brand' => ['nullable', 'string', 'max:255'],
        'model_size' => ['nullable', 'string', 'max:255'],
        'image' => ['nullable', 'string', 'max:255'],
        'buying_price' => ['required', 'numeric', 'min:0'],
        'selling_price' => ['required', 'numeric', 'min:0'],
        'wholesale_price' => ['nullable', 'numeric', 'min:0'],
        'reorder_level' => ['required', 'numeric', 'min:0'],
        'taxable' => ['boolean'],
        'status' => ['required', 'in:active,inactive'],
    ]);

    $validated['branch_id'] = $validated['branch_id'] ?: null;
    $validated['barcode'] = $validated['barcode'] ?: null;
    $validated['wholesale_price'] = $validated['wholesale_price'] === '' ? null : $validated['wholesale_price'];

    $product = $this->editing_product_id
        ? Product::findOrFail($this->editing_product_id)
        : new Product();

    $product->fill($validated)->save();

    session()->flash('success', $this->editing_product_id ? 'Product updated successfully.' : 'Product created successfully.');
    $this->resetProductForm();
    $this->dispatch('close-modal', 'product-form');
};

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

$confirmDeleteProduct = function (int $productId) {
    abort_unless($this->canManage(), 403);

    $this->deleting_product_id = $productId;
    $this->dispatch('open-modal', 'delete-product');
};

$deleteConfirmedProduct = function () {
    abort_unless($this->canManage(), 403);

    Product::findOrFail($this->deleting_product_id)->delete();
    $this->deleting_product_id = null;

    session()->flash('success', 'Product deleted.');
    $this->dispatch('close-modal', 'delete-product');
};

?>

<div>
    <x-page-header
        title="Products"
        description="Product master data only. Stock quantities will come from stock movements in Phase 3 and Phase 4."
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Products' => null]"
    >
        @if ($this->canManage())
            <button type="button" wire:click="openCreateProduct" class="erp-btn-primary">Create Product</button>
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
                            <div class="hs-dropdown relative inline-flex [--placement:bottom-end] [--strategy:fixed]">
                                <button type="button" class="hs-dropdown-toggle rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
                                    Actions
                                </button>
                                <div class="hs-dropdown-menu z-[90] mt-2 hidden min-w-40 rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg dark:border-slate-700 dark:bg-slate-900" role="menu">
                                    <button type="button" onclick="this.closest('.hs-dropdown')?.querySelector('.hs-dropdown-toggle')?.click()" wire:click="openEditProduct({{ $product->id }})" class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-white/5">Edit</button>
                                    <button type="button" onclick="this.closest('.hs-dropdown')?.querySelector('.hs-dropdown-toggle')?.click()" wire:click="toggleStatus({{ $product->id }})" class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-white/5">{{ $product->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                                    <button type="button" onclick="this.closest('.hs-dropdown')?.querySelector('.hs-dropdown-toggle')?.click()" wire:click="confirmDeleteProduct({{ $product->id }})" class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10">Delete</button>
                                </div>
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

    <x-modal name="product-form" maxWidth="2xl">
        <form wire:submit="saveProduct" class="flex min-h-full flex-col sm:max-h-[calc(100vh-3rem)]">
            <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ $editing_product_id ? 'Edit Product' : 'Add Product' }}</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Create and update product master data without leaving this page.</p>
                </div>
                <button type="button" x-on:click="$dispatch('close-modal', 'product-form')" class="erp-btn-secondary px-2 py-1" wire:loading.attr="disabled">Close</button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-5">
                <div class="grid gap-4 md:grid-cols-2">
                    <x-form-input label="Product Name" name="name" wire:model="name" required />
                    <x-form-input label="SKU" name="sku" wire:model="sku" required />
                    <x-form-input label="Barcode" name="barcode" wire:model="barcode" />

                    <x-form-select label="Category" name="category_id" wire:model="category_id" required>
                        <option value="">Select category</option>
                        @foreach (Category::where('status', 'active')->orderBy('name')->get() as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </x-form-select>

                    <x-form-select label="Unit" name="unit_id" wire:model="unit_id" required>
                        <option value="">Select unit</option>
                        @foreach (Unit::where('status', 'active')->orderBy('name')->get() as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->name }} / {{ $unit->short_name }}</option>
                        @endforeach
                    </x-form-select>

                    <x-form-select label="Branch" name="branch_id" wire:model="branch_id">
                        <option value="">Global product</option>
                        @foreach (Branch::orderBy('name')->get() as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </x-form-select>

                    <x-form-input label="Brand" name="brand" wire:model="brand" />
                    <x-form-input label="Model / Size" name="model_size" wire:model="model_size" />
                    <x-form-input label="Product Image Path" name="image" wire:model="image" placeholder="products/item.jpg" />
                    <x-form-input label="Buying Price" name="buying_price" type="number" step="0.01" wire:model="buying_price" required />
                    <x-form-input label="Selling Price" name="selling_price" type="number" step="0.01" wire:model="selling_price" required />
                    <x-form-input label="Wholesale Price" name="wholesale_price" type="number" step="0.01" wire:model="wholesale_price" />
                    <x-form-input label="Reorder Level" name="reorder_level" type="number" step="0.01" wire:model="reorder_level" required />

                    <x-form-select label="Status" name="status" wire:model="status" required>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </x-form-select>

                    <label class="flex items-center gap-3 self-end rounded-xl border border-slate-200 px-4 py-3 text-sm font-medium dark:border-slate-700">
                        <input type="checkbox" wire:model="taxable" class="rounded border-slate-300 text-build-orange focus:ring-build-orange dark:border-slate-700 dark:bg-slate-950">
                        Taxable product
                    </label>
                </div>
            </div>

            <div class="flex flex-col-reverse gap-2 border-t border-slate-200 px-5 py-4 dark:border-slate-700 sm:flex-row sm:justify-end">
                <button type="button" x-on:click="$dispatch('close-modal', 'product-form')" class="erp-btn-secondary" wire:loading.attr="disabled">Cancel</button>
                <button class="erp-btn-primary" wire:loading.attr="disabled" wire:target="saveProduct">
                    <span wire:loading.remove wire:target="saveProduct">{{ $editing_product_id ? 'Update Product' : 'Save Product' }}</span>
                    <span wire:loading wire:target="saveProduct">Saving...</span>
                </button>
            </div>
        </form>
    </x-modal>

    <x-modal name="delete-product" maxWidth="md" :closeOnBackdrop="false">
        @php($deletingProduct = $deleting_product_id ? Product::find($deleting_product_id) : null)
        <div class="p-5">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Confirm Delete</h2>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                Are you sure you want to delete {{ $deletingProduct?->name ? '"'.$deletingProduct->name.'"' : 'this record' }}?
            </p>

            <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" x-on:click="$dispatch('close-modal', 'delete-product')" class="erp-btn-secondary" wire:loading.attr="disabled">Cancel</button>
                <button type="button" wire:click="deleteConfirmedProduct" wire:loading.attr="disabled" wire:target="deleteConfirmedProduct" class="inline-flex items-center justify-center rounded-lg bg-red-500 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-600 disabled:pointer-events-none disabled:opacity-50">
                    <span wire:loading.remove wire:target="deleteConfirmedProduct">Delete</span>
                    <span wire:loading wire:target="deleteConfirmedProduct">Deleting...</span>
                </button>
            </div>
        </div>
    </x-modal>
</div>
