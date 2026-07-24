<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\MeasurementType;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\Unit;
use App\Support\ProductMeasurementOptions;
use Illuminate\Validation\Rule;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'product' => null,
    'branch_id' => '',
    'category_id' => '',
    'measurement_type_id' => '',
    'purchase_unit_id' => '',
    'purchase_conversion_factor' => '1',
    'unit_id' => '',
    'product_size_id' => '',
    'product_size_search' => '',
    'uses_product_size' => false,
    'confirm_size_removal' => false,
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
    'tracks_batch' => false,
    'tracks_expiry' => false,
    'reorder_level' => '0',
    'taxable' => false,
    'status' => 'active',
]);

mount(function (Product $product) {
    $this->product = $product;
    $this->branch_id = (string) $product->branch_id;
    $this->category_id = (string) $product->category_id;
    $this->measurement_type_id = (string) $product->measurement_type_id;
    $this->purchase_unit_id = (string) ($product->purchase_unit_id ?: $product->unit_id);
    $this->purchase_conversion_factor = (string) ($product->purchase_conversion_factor ?: 1);
    $this->unit_id = (string) $product->unit_id;
    $this->product_size_id = (string) $product->product_size_id;
    $this->uses_product_size = (bool) $product->uses_product_size || filled($product->product_size_id);
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
    $this->tracks_batch = (bool) $product->tracks_batch;
    $this->tracks_expiry = (bool) $product->tracks_expiry;
    $this->reorder_level = (string) $product->reorder_level;
    $this->taxable = $product->taxable;
    $this->status = $product->status;
});

$measurementCode = fn () => MeasurementType::query()->whereKey($this->measurement_type_id)->value('code') ?? $this->product?->measurementCode();
$isLength = fn () => $this->measurementCode() === MeasurementType::LENGTH;
$isCount = fn () => $this->measurementCode() === MeasurementType::COUNT;
$categorySupportsProductSizes = fn () => (bool) Category::query()->whereKey($this->category_id)->value('supports_product_sizes');
$showsProductSize = fn () => (bool) $this->uses_product_size
    || filled($this->product_size_id)
    || $this->isLength()
    || $this->categorySupportsProductSizes();
$showsFractionalConfiguration = fn () => filled($this->measurement_type_id) && ! $this->isCount();
$requiresUnitConversion = fn () => filled($this->unit_id)
    && filled($this->selling_unit_id)
    && (string) $this->unit_id !== (string) $this->selling_unit_id;
$requiresPurchaseConversion = fn () => filled($this->purchase_unit_id)
    && filled($this->unit_id)
    && (string) $this->purchase_unit_id !== (string) $this->unit_id;
$purchaseUnits = function () {
    $units = ProductMeasurementOptions::purchaseUnits(
        (string) $this->measurementCode(),
        filled($this->purchase_unit_id) ? (int) $this->purchase_unit_id : null,
    );

    if ($this->product?->purchase_unit_id && ! $units->contains('id', $this->product->purchase_unit_id)) {
        $units->push(Unit::find($this->product->purchase_unit_id));
    }

    return $units->filter()->sortBy('name')->values();
};
$keepsLegacyAssignedUnits = fn () => (string) $this->measurement_type_id === (string) $this->product?->measurement_type_id;
$baseUnits = function () {
    $units = ProductMeasurementOptions::baseUnits(
        (string) $this->measurementCode(),
        filled($this->unit_id) ? (int) $this->unit_id : null,
    );

    if ($this->keepsLegacyAssignedUnits() && $this->product?->unit_id && ! $units->contains('id', $this->product->unit_id)) {
        $units->push(Unit::find($this->product->unit_id));
    }

    return $units->filter()->sortBy('name')->values();
};
$sellingUnits = function () {
    $units = ProductMeasurementOptions::sellingUnits(
        (string) $this->measurementCode(),
        filled($this->selling_unit_id) ? (int) $this->selling_unit_id : null,
    );
    $legacySellingUnitId = $this->product?->selling_unit_id ?: $this->product?->unit_id;

    if ($this->keepsLegacyAssignedUnits() && $legacySellingUnitId && ! $units->contains('id', $legacySellingUnitId)) {
        $units->push(Unit::find($legacySellingUnitId));
    }

    return $units->filter()->sortBy('name')->values();
};

$updatedMeasurementTypeId = function () {
    $code = $this->measurementCode();

    if (! $code) {
        return;
    }

    $defaults = ProductMeasurementOptions::defaults($code);
    $validBaseIds = ProductMeasurementOptions::baseUnits($code, filled($this->unit_id) ? (int) $this->unit_id : null)
        ->pluck('id')->map(fn ($id) => (string) $id)->all();
    $validSellingIds = ProductMeasurementOptions::sellingUnits($code, filled($this->selling_unit_id) ? (int) $this->selling_unit_id : null)
        ->pluck('id')->map(fn ($id) => (string) $id)->all();
    $baseWasValid = filled($this->unit_id) && in_array((string) $this->unit_id, $validBaseIds, true);
    $sellingWasValid = filled($this->selling_unit_id) && in_array((string) $this->selling_unit_id, $validSellingIds, true);

    if (! $baseWasValid) {
        $this->unit_id = $defaults['unit_id'] ? (string) $defaults['unit_id'] : '';
    }

    if (! $sellingWasValid) {
        $this->selling_unit_id = $defaults['selling_unit_id'] ? (string) $defaults['selling_unit_id'] : '';
    }

    $this->allow_fractional_sale = $defaults['allow_fractional_sale'];
    $this->minimum_sale_quantity = $defaults['minimum_sale_quantity'];
    $this->quantity_step = $defaults['quantity_step'];
    if (! $this->requiresUnitConversion()) {
        $this->conversion_factor = '1';
    } elseif (! $baseWasValid || ! $sellingWasValid) {
        $this->conversion_factor = $defaults['conversion_factor'];
    }
    $this->uses_product_size = $code === MeasurementType::LENGTH
        || $this->categorySupportsProductSizes()
        || filled($this->product_size_id);
    $this->resetErrorBag([
        'unit_id',
        'selling_unit_id',
        'product_size_id',
        'conversion_factor',
        'allow_fractional_sale',
        'minimum_sale_quantity',
        'quantity_step',
    ]);
};

$updatedUnitId = function () {
    if (! filled($this->purchase_unit_id)) {
        $this->purchase_unit_id = $this->unit_id;
    }
    if (! $this->requiresPurchaseConversion()) {
        $this->purchase_conversion_factor = '1';
    }
    if (! $this->requiresUnitConversion()) {
        $this->conversion_factor = '1';
    } elseif ((string) $this->conversion_factor === '1') {
        $this->conversion_factor = '';
    }
};

$updatedPurchaseUnitId = function () {
    if (! $this->requiresPurchaseConversion()) {
        $this->purchase_conversion_factor = '1';
    } elseif ((string) $this->purchase_conversion_factor === '1') {
        $this->purchase_conversion_factor = '';
    }
};

$updatedSellingUnitId = function () {
    $this->updatedUnitId();
};

$updatedCategoryId = function () {
    if ($this->categorySupportsProductSizes()) {
        $this->uses_product_size = true;
    }
};

$updatedUsesProductSize = function () {
    if ($this->uses_product_size) {
        $this->confirm_size_removal = false;
    }
};

$rules = fn () => [
    'branch_id' => ['nullable', 'exists:branches,id'],
    'category_id' => ['required', 'exists:categories,id'],
    'measurement_type_id' => ['required', 'exists:measurement_types,id'],
    'purchase_unit_id' => [
        'required',
        'exists:units,id',
        Rule::in($this->purchaseUnits()->pluck('id')->map(fn ($id) => (int) $id)->all()),
    ],
    'purchase_conversion_factor' => [$this->requiresPurchaseConversion() ? 'required' : 'nullable', 'numeric', 'gt:0'],
    'unit_id' => [
        'required',
        'exists:units,id',
        Rule::in($this->baseUnits()->pluck('id')->map(fn ($id) => (int) $id)->all()),
    ],
    'product_size_id' => ['nullable', 'exists:product_sizes,id'],
    'uses_product_size' => ['boolean'],
    'confirm_size_removal' => [
        Rule::excludeIf($this->uses_product_size || blank($this->product_size_id)),
        'required',
        'accepted',
    ],
    'selling_unit_id' => [
        'required',
        'exists:units,id',
        Rule::in($this->sellingUnits()->pluck('id')->map(fn ($id) => (int) $id)->all()),
    ],
    'name' => ['required', 'string', 'max:255'],
    'sku' => ['nullable', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($this->product->id)],
    'barcode' => ['nullable', 'string', 'max:100', Rule::unique('products', 'barcode')->ignore($this->product->id)],
    'brand' => ['nullable', 'string', 'max:255'],
    'model_size' => ['nullable', 'string', 'max:255'],
    'image' => ['nullable', 'string', 'max:255'],
    'buying_price' => ['required', 'numeric', 'min:0'],
    'selling_price' => ['required', 'numeric', 'min:0'],
    'wholesale_price' => ['nullable', 'numeric', 'min:0'],
    'conversion_factor' => [$this->requiresUnitConversion() ? 'required' : 'nullable', 'numeric', 'gt:0'],
    'allow_fractional_sale' => ['boolean'],
    'minimum_sale_quantity' => [$this->showsFractionalConfiguration() ? 'required' : 'nullable', 'numeric', 'gt:0'],
    'quantity_step' => [$this->showsFractionalConfiguration() ? 'required' : 'nullable', 'numeric', 'gt:0'],
    'tracks_batch' => ['boolean'],
    'tracks_expiry' => ['boolean'],
    'reorder_level' => ['required', 'numeric', 'min:0'],
    'taxable' => ['boolean'],
    'status' => ['required', 'in:active,inactive'],
];

$save = function () {
    $validated = $this->validate($this->rules());
    $validated['branch_id'] = $validated['branch_id'] ?: null;
    $removingExistingSize = ! $validated['uses_product_size'] && filled($validated['product_size_id']);
    $validated['uses_product_size'] = (bool) $validated['uses_product_size']
        || $this->isLength()
        || $this->categorySupportsProductSizes();
    $validated['product_size_id'] = $removingExistingSize
        ? null
        : ($validated['product_size_id'] ?: null);
    unset($validated['confirm_size_removal']);
    $validated['sku'] = $validated['sku'] ?: null;
    $validated['barcode'] = $validated['barcode'] ?: null;
    $validated['wholesale_price'] = $validated['wholesale_price'] === '' ? null : $validated['wholesale_price'];

    $validated['selling_unit_id'] = $validated['selling_unit_id'] ?: $validated['unit_id'];
    $validated['purchase_conversion_factor'] = $this->requiresPurchaseConversion()
        ? $validated['purchase_conversion_factor']
        : 1;

    if (! $this->isCount()) {
        $validated['conversion_factor'] = $this->requiresUnitConversion() ? $validated['conversion_factor'] : 1;
        $validated['minimum_sale_quantity'] = $validated['minimum_sale_quantity'] ?: 1;
        $validated['quantity_step'] = $validated['quantity_step'] ?: 1;
    } else {
        $validated['allow_fractional_sale'] = false;
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
                Measurement Type
                <select wire:model.live="measurement_type_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">Select measurement type</option>
                    @foreach (MeasurementType::orderBy('sort_order')->get() as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                    @endforeach
                </select>
                @error('measurement_type_id') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                Purchase Unit
                <select wire:model.live="purchase_unit_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">Select purchase unit</option>
                    @foreach ($this->purchaseUnits() as $unit)
                        <option value="{{ $unit->id }}">{{ $unit->name }} / {{ $unit->short_name }}</option>
                    @endforeach
                </select>
                @error('purchase_unit_id') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
            </label>

            <label wire:key="base-unit-{{ $measurement_type_id ?: 'none' }}" class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                Base Stock Unit
                <select wire:model.live="unit_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">Select unit</option>
                    @foreach ($this->baseUnits() as $unit)
                        <option value="{{ $unit->id }}">{{ $unit->name }} / {{ $unit->short_name }}</option>
                    @endforeach
                </select>
                @error('unit_id') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
            </label>

            @if ($this->requiresPurchaseConversion())
                @php
                    $purchaseUnit = Unit::find($purchase_unit_id);
                    $stockUnit = Unit::find($unit_id);
                @endphp
                <div>
                    <x-form-input label="Purchase to Stock Factor" name="purchase_conversion_factor" type="number" step="0.0001" wire:model="purchase_conversion_factor" required />
                    <p class="mt-1 text-xs font-semibold text-slate-500">1 {{ $purchaseUnit?->short_name }} = [factor] {{ $stockUnit?->short_name }}</p>
                </div>
            @endif

            <label wire:key="selling-unit-{{ $measurement_type_id ?: 'none' }}" class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                Selling Unit
                <select wire:model.live="selling_unit_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">Select selling unit</option>
                    @foreach ($this->sellingUnits() as $unit)
                        <option value="{{ $unit->id }}">{{ $unit->name }} / {{ $unit->short_name }}</option>
                    @endforeach
                </select>
                <span class="mt-1 block text-xs font-semibold text-slate-500">Filtered for {{ strtolower($this->measurementCode()) }} products.</span>
                @error('selling_unit_id') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="flex items-center gap-3 self-end rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold dark:border-slate-800">
                <input type="checkbox" wire:model.live="uses_product_size" class="rounded border-slate-300 text-build-orange focus:ring-build-orange">
                Uses Product Size
            </label>

            @if (! $uses_product_size && filled($product_size_id))
                <label class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-900 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-200">
                    <span class="flex items-start gap-3">
                        <input type="checkbox" wire:model="confirm_size_removal" class="mt-0.5 rounded border-amber-400 text-amber-600 focus:ring-amber-500">
                        Confirm removal of the existing Product Size when this product is saved.
                    </span>
                    @error('confirm_size_removal') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
            @endif

            @if ($this->showsProductSize())
                <label wire:transition class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                    Product Size
                    <input wire:model.live.debounce.300ms="product_size_search" class="mt-1 block w-full rounded-t-lg border border-b-0 border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="Search size, e.g. 2 × 4">
                    <select wire:model="product_size_id" class="block w-full rounded-b-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        <option value="">No size</option>
                        @foreach (ProductSize::query()->where(fn ($query) => $query->where('status', 'active')->when($product_size_id, fn ($q) => $q->orWhere('id', $product_size_id)))->when($product_size_search, fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$product_size_search}%")->orWhere('symbol', 'like', "%{$product_size_search}%")))->orderBy('symbol')->limit(50)->get() as $size)
                            <option value="{{ $size->id }}">{{ $size->label() }}</option>
                        @endforeach
                    </select>
                    @error('product_size_id') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                </label>
            @endif

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
                <p class="text-sm font-black text-slate-800 dark:text-slate-100">Stock Traceability</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Enable only when receiving this product requires batch or expiry information.</p>
                <div class="mt-3 flex flex-wrap gap-5">
                    <label class="flex items-center gap-3 text-sm font-bold">
                        <input type="checkbox" wire:model="tracks_batch" class="rounded border-slate-300 text-build-orange focus:ring-build-orange">
                        Track Batch Number
                    </label>
                    <label class="flex items-center gap-3 text-sm font-bold">
                        <input type="checkbox" wire:model="tracks_expiry" class="rounded border-slate-300 text-build-orange focus:ring-build-orange">
                        Track Expiry Date
                    </label>
                </div>
            </div>

            @if ($showFractionalConfiguration)
            <div wire:transition class="rounded-xl border border-slate-200 p-4 dark:border-slate-800 md:col-span-2 xl:col-span-3">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm font-black text-slate-800 dark:text-slate-100">Fractional Selling</p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Configure decimal selling for {{ strtolower($this->measurementCode()) }} products.
                        </p>
                    </div>
                    <label class="flex items-center gap-3 text-sm font-bold">
                        <input type="checkbox" wire:model.live="allow_fractional_sale" class="rounded border-slate-300 text-build-orange focus:ring-build-orange">
                        Allow Fraction
                    </label>
                </div>

                    <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        @if ($this->requiresUnitConversion())
                            @php
                                $baseUnit = Unit::find($unit_id);
                                $sellingUnit = Unit::find($selling_unit_id);
                            @endphp
                            <div>
                                <x-form-input label="Conversion Factor" name="conversion_factor" type="number" step="0.0001" wire:model="conversion_factor" required />
                                <p class="mt-1 text-xs font-semibold text-slate-500">
                                    1 {{ $baseUnit?->short_name }} = [conversion factor] {{ $sellingUnit?->short_name }}
                                </p>
                            </div>
                        @endif
                        <x-form-input label="Minimum Sale Quantity" name="minimum_sale_quantity" type="number" step="0.0001" wire:model="minimum_sale_quantity" required />
                        <x-form-input label="Quantity Step" name="quantity_step" type="number" step="0.0001" wire:model="quantity_step" required />
                    </div>
            </div>
            @endif

            <div class="flex gap-2 md:col-span-2 xl:col-span-3">
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Update Product</button>
                <a href="{{ route('products.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Cancel</a>
            </div>
        </form>
    </x-card>
</div>
