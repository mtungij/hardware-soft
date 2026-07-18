<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Validation\Rule;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'product' => null,
    'branch_id' => '',
    'category_id' => '',
    'unit_id' => '',
    'selling_unit_id' => '',
    'name' => '',
    'sku' => '',
    'barcode' => '',
    'brand' => '',
    'model_size' => '',
    'image' => '',
    'buying_price' => '0',
    'selling_price' => '0',
    'wholesale_price' => '',
    'conversion_factor' => '1',
    'allow_fractional_sale' => false,
    'minimum_sale_quantity' => '1',
    'quantity_step' => '1',
    'reorder_level' => '0',
    'taxable' => false,
    'status' => 'active',
]);

mount(function (Product $product) {
    $this->product = $product;
    $this->branch_id = (string) $product->branch_id;
    $this->category_id = (string) $product->category_id;
    $this->unit_id = (string) $product->unit_id;
    $this->selling_unit_id = (string) ($product->selling_unit_id ?: $product->unit_id);
    $this->name = $product->name;
    $this->sku = $product->sku;
    $this->barcode = $product->barcode;
    $this->brand = $product->brand;
    $this->model_size = $product->model_size;
    $this->image = $product->image;
    $this->buying_price = (string) $product->buying_price;
    $this->selling_price = (string) $product->selling_price;
    $this->wholesale_price = (string) $product->wholesale_price;
    $this->conversion_factor = (string) ($product->conversion_factor ?: 1);
    $this->allow_fractional_sale = (bool) $product->allow_fractional_sale;
    $this->minimum_sale_quantity = (string) ($product->minimum_sale_quantity ?: 1);
    $this->quantity_step = (string) ($product->quantity_step ?: 1);
    $this->reorder_level = (string) $product->reorder_level;
    $this->taxable = $product->taxable;
    $this->status = $product->status;
});

$selectedCategoryAllowsFractional = fn () => (bool) Category::query()
    ->whereKey($this->category_id)
    ->value('allow_fractional_sales');

$showsFractionalConfiguration = fn () => (bool) $this->allow_fractional_sale || $this->selectedCategoryAllowsFractional();

$rules = fn () => [
    'branch_id' => ['nullable', 'exists:branches,id'],
    'category_id' => ['required', 'exists:categories,id'],
    'unit_id' => ['required', 'exists:units,id'],
    'selling_unit_id' => ['nullable', 'exists:units,id'],
    'name' => ['required', 'string', 'max:255'],
    'sku' => ['nullable', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($this->product->id)],
    'barcode' => ['nullable', 'string', 'max:100', Rule::unique('products', 'barcode')->ignore($this->product->id)],
    'brand' => ['nullable', 'string', 'max:255'],
    'model_size' => ['nullable', 'string', 'max:255'],
    'image' => ['nullable', 'string', 'max:255'],
    'buying_price' => ['required', 'numeric', 'min:0'],
    'selling_price' => ['required', 'numeric', 'min:0'],
    'wholesale_price' => ['nullable', 'numeric', 'min:0'],
    'conversion_factor' => [$this->showsFractionalConfiguration() ? 'required' : 'nullable', 'numeric', 'gt:0'],
    'allow_fractional_sale' => ['boolean'],
    'minimum_sale_quantity' => [$this->showsFractionalConfiguration() ? 'required' : 'nullable', 'numeric', 'gt:0'],
    'quantity_step' => [$this->showsFractionalConfiguration() ? 'required' : 'nullable', 'numeric', 'gt:0'],
    'reorder_level' => ['required', 'numeric', 'min:0'],
    'taxable' => ['boolean'],
    'status' => ['required', 'in:active,inactive'],
];

$save = function () {
    $validated = $this->validate($this->rules());
    $usesFractionalConfiguration = (bool) $validated['allow_fractional_sale'] || $this->selectedCategoryAllowsFractional();

    $validated['branch_id'] = $validated['branch_id'] ?: null;
    $validated['sku'] = $validated['sku'] ?: null;
    $validated['barcode'] = $validated['barcode'] ?: null;
    $validated['wholesale_price'] = $validated['wholesale_price'] === '' ? null : $validated['wholesale_price'];

    if ($usesFractionalConfiguration) {
        $validated['selling_unit_id'] = $validated['selling_unit_id'] ?: $validated['unit_id'];
        $validated['conversion_factor'] = $validated['conversion_factor'] ?: 1;
        $validated['minimum_sale_quantity'] = $validated['minimum_sale_quantity'] ?: 1;
        $validated['quantity_step'] = $validated['quantity_step'] ?: 1;
    } else {
        $validated['selling_unit_id'] = $validated['unit_id'];
        $validated['conversion_factor'] = 1;
        $validated['minimum_sale_quantity'] = 1;
        $validated['quantity_step'] = 1;
    }

    $this->product->update($validated);

    session()->flash('success', 'Product updated successfully.');
    $this->redirectRoute('products.index', navigate: true);
};

?>

<div>
    <x-page-header
        title="Edit Product"
        description="Update product master data without storing stock quantities."
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Products' => route('products.index'), 'Edit' => null]"
    />

    <x-card>
        <form wire:submit="save" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @php
                $categoryAllowsFractional = $this->selectedCategoryAllowsFractional();
                $showFractionalConfiguration = $this->showsFractionalConfiguration();
            @endphp

            <x-form-input label="Product Name" name="name" wire:model="name" required />
            <x-form-input label="SKU" name="sku" wire:model="sku" />
            <x-form-input label="Barcode" name="barcode" wire:model="barcode" />

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                Category
                <select wire:model.live="category_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">Select category</option>
                    @foreach (Category::orderBy('name')->get() as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
                @error('category_id') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                Base Stock Unit
                <select wire:model="unit_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">Select unit</option>
                    @foreach (Unit::orderBy('name')->get() as $unit)
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
            <x-money-input label="Buying Price" name="buying_price" value="{{ $buying_price }}" wire:model="buying_price" required />
            <x-money-input label="Selling Price" name="selling_price" value="{{ $selling_price }}" wire:model="selling_price" required />
            <x-money-input label="Wholesale Price" name="wholesale_price" value="{{ $wholesale_price }}" wire:model="wholesale_price" />
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

            <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-800 md:col-span-2 xl:col-span-3">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm font-black text-slate-800 dark:text-slate-100">Fractional Selling</p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            {{ $categoryAllowsFractional ? 'Selected category allows fractional sales.' : 'Enable only for items sold by partial unit.' }}
                        </p>
                    </div>
                    <label class="flex items-center gap-3 text-sm font-bold">
                        <input type="checkbox" wire:model.live="allow_fractional_sale" class="rounded border-slate-300 text-build-orange focus:ring-build-orange">
                        Allow Fractional Sale override
                    </label>
                </div>

                @if ($showFractionalConfiguration)
                    <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                            Selling Unit
                            <select wire:model="selling_unit_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                                <option value="">Same as base unit</option>
                                @foreach (Unit::orderBy('name')->get() as $unit)
                                    <option value="{{ $unit->id }}">{{ $unit->name }} / {{ $unit->short_name }}</option>
                                @endforeach
                            </select>
                            @error('selling_unit_id') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                        </label>

                        <x-form-input label="Conversion Factor" name="conversion_factor" type="number" step="0.0001" wire:model="conversion_factor" required />
                        <x-form-input label="Minimum Sale Quantity" name="minimum_sale_quantity" type="number" step="0.0001" wire:model="minimum_sale_quantity" required />
                        <x-form-input label="Quantity Step" name="quantity_step" type="number" step="0.0001" wire:model="quantity_step" required />
                    </div>
                @endif
            </div>

            <div class="flex gap-2 md:col-span-2 xl:col-span-3">
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Update Product</button>
                <a href="{{ route('products.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Cancel</a>
            </div>
        </form>
    </x-card>
</div>
