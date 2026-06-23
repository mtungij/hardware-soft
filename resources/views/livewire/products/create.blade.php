<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;

use function Livewire\Volt\layout;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.app');

state([
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

rules([
    'branch_id' => ['nullable', 'exists:branches,id'],
    'category_id' => ['required', 'exists:categories,id'],
    'unit_id' => ['required', 'exists:units,id'],
    'name' => ['required', 'string', 'max:255'],
    'sku' => ['required', 'string', 'max:100', 'unique:products,sku'],
    'barcode' => ['nullable', 'string', 'max:100', 'unique:products,barcode'],
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

$save = function () {
    $validated = $this->validate();
    $validated['branch_id'] = $validated['branch_id'] ?: null;
    $validated['barcode'] = $validated['barcode'] ?: null;
    $validated['wholesale_price'] = $validated['wholesale_price'] === '' ? null : $validated['wholesale_price'];

    Product::create($validated);

    session()->flash('success', 'Product created successfully.');
    $this->redirectRoute('products.index', navigate: true);
};

?>

<div>
    <x-page-header
        title="Create Product"
        description="Create product master data. Do not enter stock quantities here."
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Products' => route('products.index'), 'Create' => null]"
    />

    <x-card>
        <form wire:submit="save" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <x-form-input label="Product Name" name="name" wire:model="name" required />
            <x-form-input label="SKU" name="sku" wire:model="sku" required />
            <x-form-input label="Barcode" name="barcode" wire:model="barcode" />

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                Category
                <select wire:model="category_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">Select category</option>
                    @foreach (Category::where('status', 'active')->orderBy('name')->get() as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
                @error('category_id') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                Unit
                <select wire:model="unit_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">Select unit</option>
                    @foreach (Unit::where('status', 'active')->orderBy('name')->get() as $unit)
                        <option value="{{ $unit->id }}">{{ $unit->name }} / {{ $unit->short_name }}</option>
                    @endforeach
                </select>
                @error('unit_id') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                Branch
                <select wire:model="branch_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">Global product</option>
                    @foreach (Branch::orderBy('name')->get() as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            </label>

            <x-form-input label="Brand" name="brand" wire:model="brand" />
            <x-form-input label="Model / Size" name="model_size" wire:model="model_size" />
            <x-form-input label="Product Image Path" name="image" wire:model="image" placeholder="products/item.jpg" />
            <x-form-input label="Buying Price" name="buying_price" type="number" step="0.01" wire:model="buying_price" required />
            <x-form-input label="Selling Price" name="selling_price" type="number" step="0.01" wire:model="selling_price" required />
            <x-form-input label="Wholesale Price" name="wholesale_price" type="number" step="0.01" wire:model="wholesale_price" />
            <x-form-input label="Reorder Level" name="reorder_level" type="number" step="0.01" wire:model="reorder_level" required />

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                Status
                <select wire:model="status" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </label>

            <label class="flex items-center gap-3 self-end rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold dark:border-slate-800">
                <input type="checkbox" wire:model="taxable" class="rounded border-slate-300 text-build-orange focus:ring-build-orange">
                Taxable product
            </label>

            <div class="flex gap-2 md:col-span-2 xl:col-span-3">
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Save Product</button>
                <a href="{{ route('products.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Cancel</a>
            </div>
        </form>
    </x-card>
</div>
