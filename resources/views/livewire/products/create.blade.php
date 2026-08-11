<?php

use App\Livewire\Concerns\ManagesProductUnitConversionRows;
use App\Models\Category;
use App\Models\MeasurementType;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Services\ProductImageService;
use App\Services\ProductUnitConversionService;
use App\Support\CompanyFeatures;
use App\Support\ProductMeasurementOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\WithFileUploads;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithFileUploads::class, ManagesProductUnitConversionRows::class]);

state([
    'branch_id' => '',
    'category_id' => '',
    'measurement_type_id' => '',
    'purchase_unit_id' => '',
    'purchase_conversion_factor' => '1',
    'unit_id' => '',
    'product_size_id' => '',
    'product_size_search' => '',
    'uses_product_size' => false,
    'selling_unit_id' => '',
    'name' => '',
    'sku' => '',
    'barcode' => '',
    'brand' => '',
    'model_size' => '',
    'image' => '',
    'image_upload' => null,
    'remove_image' => false,
    'inventory_source' => Product::INVENTORY_SOURCE_PURCHASED,
    'product_family_id' => '',
    'family_defaults_applied' => false,
    'requires_curing' => false,
    'curing_days_required' => '',
    'sellable_after_days' => '',
    'curing_notes' => '',
    'requires_quality_control' => false,
    'requires_pre_release_inspection' => false,
    'quality_notes' => '',
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
    'unit_conversions' => [],
]);

mount(function (): void {
    if (CompanyFeatures::manufacturingEnabled() && CompanyFeatures::companyId()) {
        ProductFamily::ensureDefaultsForCompany((int) CompanyFeatures::companyId());
    }
});

$measurementCode = fn () => MeasurementType::query()->whereKey($this->measurement_type_id)->value('code');
$isLength = fn () => $this->measurementCode() === MeasurementType::LENGTH;
$isCount = fn () => $this->measurementCode() === MeasurementType::COUNT;
$categorySupportsProductSizes = fn () => (bool) Category::query()->whereKey($this->category_id)->value('supports_product_sizes');
$showsProductSize = fn () => (bool) $this->uses_product_size
    || filled($this->product_size_id)
    || $this->isLength()
    || $this->categorySupportsProductSizes();
$showsFractionalConfiguration = fn () => filled($this->measurement_type_id) && ! $this->isCount();
$manufacturingEnabled = fn () => CompanyFeatures::manufacturingEnabled();
$showsCuringConfiguration = fn () => $this->manufacturingEnabled()
    && $this->inventory_source === Product::INVENTORY_SOURCE_MANUFACTURED;
$requiresUnitConversion = fn () => filled($this->unit_id)
    && filled($this->selling_unit_id)
    && (string) $this->unit_id !== (string) $this->selling_unit_id;
$requiresPurchaseConversion = fn () => filled($this->purchase_unit_id)
    && filled($this->unit_id)
    && (string) $this->purchase_unit_id !== (string) $this->unit_id;
$purchaseUnits = fn () => ProductMeasurementOptions::purchaseUnits(
    (string) $this->measurementCode(),
    filled($this->purchase_unit_id) ? (int) $this->purchase_unit_id : null,
);
$baseUnits = fn () => ProductMeasurementOptions::baseUnits(
    (string) $this->measurementCode(),
    filled($this->unit_id) ? (int) $this->unit_id : null,
);
$sellingUnits = fn () => ProductMeasurementOptions::sellingUnits(
    (string) $this->measurementCode(),
    filled($this->selling_unit_id) ? (int) $this->selling_unit_id : null,
);

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

    $this->purchase_unit_id = $this->unit_id;
    $this->purchase_conversion_factor = '1';

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

$updatedInventorySource = function () {
    if (! $this->showsCuringConfiguration()) {
        $this->product_family_id = '';
        $this->family_defaults_applied = false;
        $this->requires_curing = false;
        $this->curing_days_required = '';
        $this->sellable_after_days = '';
        $this->curing_notes = '';
        $this->requires_quality_control = false;
        $this->requires_pre_release_inspection = false;
        $this->quality_notes = '';
    } else {
        $this->product_family_id = (string) (ProductFamily::defaultForCompany((int) CompanyFeatures::companyId())?->id ?? '');
        $this->updatedProductFamilyId();
    }
};

$updatedProductFamilyId = function (): void {
    if (! $this->showsCuringConfiguration() || ! filled($this->product_family_id)) {
        return;
    }

    $family = ProductFamily::query()->forCurrentCompany()->active()->findOrFail($this->product_family_id);
    $defaults = $family->authoringDefaults();
    $this->requires_curing = (bool) $defaults['requires_curing'];
    $this->curing_days_required = $defaults['curing_days_required'] !== null ? (string) $defaults['curing_days_required'] : '';
    $this->sellable_after_days = $defaults['sellable_after_days'] !== null ? (string) $defaults['sellable_after_days'] : '';
    $this->requires_quality_control = (bool) $defaults['requires_quality_control'];
    $this->requires_pre_release_inspection = false;
    if ($defaults['unit_id']) {
        $this->unit_id = (string) $defaults['unit_id'];
        $this->purchase_unit_id = (string) $defaults['unit_id'];
        $this->purchase_conversion_factor = '1';
    }
    if ($defaults['selling_unit_id']) {
        $this->selling_unit_id = (string) $defaults['selling_unit_id'];
    }
    $this->family_defaults_applied = true;
};

$updatedImageUpload = function () {
    $this->remove_image = false;
    $this->validateOnly('image_upload', $this->rules());
};

$removeImage = function () {
    $this->reset('image_upload');
    $this->remove_image = false;
    $this->resetErrorBag('image_upload');
};

$addUnitConversion = function (): void {
    $this->appendUnitConversionRow();
};

$removeUnitConversion = function (int $index): void {
    $this->removeUnitConversionRow($index);
};

$imagePreviewUrl = function (): ?string {
    if (! $this->image_upload) {
        return null;
    }

    try {
        return $this->image_upload->temporaryUrl();
    } catch (Throwable) {
        return null;
    }
};

$rules = fn () => [
    'branch_id' => ['nullable', 'exists:branches,id'],
    'category_id' => ['required', 'exists:categories,id'],
    'measurement_type_id' => ['required', 'exists:measurement_types,id'],
    'purchase_unit_id' => [
        'required',
        'exists:units,id',
        Rule::in(ProductMeasurementOptions::purchaseUnitIds(
            (string) $this->measurementCode(),
            filled($this->purchase_unit_id) ? (int) $this->purchase_unit_id : null,
        )),
    ],
    'purchase_conversion_factor' => [$this->requiresPurchaseConversion() ? 'required' : 'nullable', 'numeric', 'gt:0'],
    'unit_id' => [
        'required',
        'exists:units,id',
        Rule::in(ProductMeasurementOptions::compatibleUnitIds(
            (string) $this->measurementCode(),
            filled($this->unit_id) ? (int) $this->unit_id : null,
        )),
    ],
    'product_size_id' => ['nullable', 'exists:product_sizes,id'],
    'uses_product_size' => ['boolean'],
    'selling_unit_id' => [
        'required',
        'exists:units,id',
        Rule::in(ProductMeasurementOptions::sellingUnitIds(
            (string) $this->measurementCode(),
            filled($this->selling_unit_id) ? (int) $this->selling_unit_id : null,
        )),
    ],
    'name' => ['required', 'string', 'max:255'],
    'sku' => ['nullable', 'string', 'max:100', 'unique:products,sku'],
    'barcode' => ['nullable', 'string', 'max:100', 'unique:products,barcode'],
    'brand' => ['nullable', 'string', 'max:255'],
    'model_size' => ['nullable', 'string', 'max:255'],
    'image' => ['nullable', 'string', 'max:255'],
    'image_upload' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
    'remove_image' => ['boolean'],
    'inventory_source' => $this->manufacturingEnabled()
        ? ['required', Rule::in([Product::INVENTORY_SOURCE_PURCHASED, Product::INVENTORY_SOURCE_MANUFACTURED])]
        : ['nullable'],
    'product_family_id' => [
        $this->showsCuringConfiguration() ? 'required' : 'nullable',
        Rule::exists('product_families', 'id')->where(fn ($query) => $query
            ->where('company_id', CompanyFeatures::companyId())->where('active', true)),
    ],
    'family_defaults_applied' => ['boolean'],
    'requires_curing' => ['boolean'],
    'curing_days_required' => [$this->showsCuringConfiguration() && $this->requires_curing ? 'required' : 'nullable', 'integer', 'min:1', 'max:65535'],
    'sellable_after_days' => [$this->showsCuringConfiguration() && $this->requires_curing ? 'required' : 'nullable', 'integer', 'min:1', 'max:65535', 'lte:curing_days_required'],
    'curing_notes' => ['nullable', 'string', 'max:5000'],
    'requires_quality_control' => ['boolean'],
    'requires_pre_release_inspection' => ['boolean'],
    'quality_notes' => ['nullable', 'string', 'max:5000'],
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
    'unit_conversions' => ['array'],
    'unit_conversions.*.unit_id' => ['required', 'exists:units,id', 'distinct'],
    'unit_conversions.*.conversion_factor' => ['required', 'numeric', 'gt:0'],
    'unit_conversions.*.retail_price' => ['nullable', 'numeric', 'min:0'],
    'unit_conversions.*.wholesale_price' => ['nullable', 'numeric', 'min:0'],
    'unit_conversions.*.purchase_price' => ['nullable', 'numeric', 'min:0'],
    'unit_conversions.*.can_purchase' => ['boolean'],
    'unit_conversions.*.can_sell' => ['boolean'],
    'unit_conversions.*.active' => ['boolean'],
];

$save = function () {
    $validated = $this->validate($this->rules());
    $imageUpload = $validated['image_upload'] ?? null;
    $unitConversions = $validated['unit_conversions'] ?? [];
    unset($validated['image_upload'], $validated['remove_image']);
    unset($validated['unit_conversions']);
    unset($validated['family_defaults_applied']);
    $validated['inventory_source'] = $this->manufacturingEnabled()
        ? $validated['inventory_source']
        : Product::INVENTORY_SOURCE_PURCHASED;
    $validated['product_family_id'] = $validated['inventory_source'] === Product::INVENTORY_SOURCE_MANUFACTURED
        ? $validated['product_family_id']
        : null;
    if ($validated['inventory_source'] !== Product::INVENTORY_SOURCE_MANUFACTURED || ! $validated['requires_curing']) {
        $validated['requires_curing'] = false;
        $validated['curing_days_required'] = null;
        $validated['sellable_after_days'] = null;
        $validated['curing_notes'] = null;
    }
    if ($validated['inventory_source'] !== Product::INVENTORY_SOURCE_MANUFACTURED || ! $validated['requires_quality_control']) {
        $validated['requires_quality_control'] = false;
        $validated['requires_pre_release_inspection'] = false;
        $validated['quality_notes'] = null;
    }
    $validated['branch_id'] = $validated['branch_id'] ?: null;
    $validated['uses_product_size'] = (bool) $validated['uses_product_size']
        || $this->isLength()
        || $this->categorySupportsProductSizes()
        || filled($validated['product_size_id']);
    $validated['product_size_id'] = $validated['uses_product_size'] && filled($validated['product_size_id'])
        ? $validated['product_size_id']
        : null;
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

    $storedPath = null;

    try {
        DB::transaction(function () use ($validated, $imageUpload, $unitConversions, &$storedPath): void {
            $product = Product::create($validated);

            app(ProductUnitConversionService::class)->sync($product, $unitConversions);

            if ($imageUpload) {
                $storedPath = app(ProductImageService::class)->store($imageUpload, $product);
                $product->update(['image_path' => $storedPath]);
            }
        });
    } catch (Throwable $exception) {
        if ($storedPath) {
            Storage::disk('public')->delete($storedPath);
        }

        throw $exception;
    }

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
            @php
                $showFractionalConfiguration = $this->showsFractionalConfiguration();
            @endphp

            <x-form-input label="Product Name" name="name" wire:model="name" required />
            <x-form-input label="SKU" name="sku" wire:model="sku" />
            <x-form-input label="Barcode" name="barcode" wire:model="barcode" />

            <x-product-image-upload :preview-url="$this->imagePreviewUrl()" />

            @if ($this->manufacturingEnabled())
                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                    {{ __('products.inventory_source.label') }}
                    <select wire:model.live="inventory_source" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        <option value="{{ Product::INVENTORY_SOURCE_PURCHASED }}">{{ __('products.inventory_source.purchased') }}</option>
                        <option value="{{ Product::INVENTORY_SOURCE_MANUFACTURED }}">{{ __('products.inventory_source.manufactured') }}</option>
                    </select>
                    @error('inventory_source') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                </label>
            @endif

            @if ($this->showsCuringConfiguration())
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/60 md:col-span-2 xl:col-span-3">
                    <label class="block text-sm font-bold text-slate-800 dark:text-slate-200">{{ __('production.product_families.family') }}
                        <select wire:model.live="product_family_id" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 bg-white text-slate-900 focus:border-cyan-500 focus:ring-cyan-500 dark:border-slate-700 dark:bg-slate-900 dark:text-white">
                            <option value="">{{ __('production.product_families.select') }}</option>
                            @foreach(ProductFamily::query()->forCurrentCompany()->active()->orderBy('name')->get() as $family)<option value="{{ $family->id }}">{{ $family->name }}</option>@endforeach
                        </select>
                        @error('product_family_id')<span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span>@enderror
                    </label>
                    @if($family_defaults_applied)<p class="mt-2 text-xs font-semibold text-slate-600 dark:text-slate-300">{{ __('production.product_families.defaults_applied') }}</p>@endif
                </div>
            @endif

            @if ($this->showsCuringConfiguration())
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-500/30 dark:bg-amber-500/10 md:col-span-2 xl:col-span-3">
                    <label class="flex items-center gap-3 text-sm font-black">
                        <input type="checkbox" wire:model.live="requires_curing" class="rounded border-slate-300 text-build-orange">
                        {{ __('production.curing.requires_curing') }}
                    </label>
                    @if ($requires_curing)
                        <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            <x-form-input :label="__('production.curing.sellable_after_days')" name="sellable_after_days" type="number" min="1" wire:model="sellable_after_days" required />
                            <x-form-input :label="__('production.curing.curing_days_required')" name="curing_days_required" type="number" min="1" wire:model="curing_days_required" required />
                            <label class="block text-sm font-bold">{{ __('production.curing.notes') }}<textarea wire:model="curing_notes" class="mt-1 block min-h-20 w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950"></textarea></label>
                        </div>
                    @endif
                </div>
            @endif

            @if ($this->showsCuringConfiguration())
                <div class="rounded-xl border border-cyan-200 bg-cyan-50 p-4 dark:border-cyan-500/30 dark:bg-cyan-500/10 md:col-span-2 xl:col-span-3">
                    <label class="flex items-center gap-3 text-sm font-black"><input type="checkbox" wire:model.live="requires_quality_control" class="rounded border-slate-300 text-build-orange">{{ __('production.quality.requires_quality_control') }}</label>
                    @if ($requires_quality_control)
                        <div class="mt-4 grid gap-4 md:grid-cols-2">
                            <label class="flex items-center gap-3 text-sm font-bold"><input type="checkbox" wire:model="requires_pre_release_inspection" class="rounded border-slate-300 text-build-orange">{{ __('production.quality.requires_pre_release') }}</label>
                            <label class="block text-sm font-bold">{{ __('production.quality.notes') }}<textarea wire:model="quality_notes" class="mt-1 block min-h-20 w-full rounded-lg border-slate-200 dark:border-slate-700 dark:bg-navy-950"></textarea></label>
                        </div>
                    @endif
                </div>
            @endif

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                Category
                <select wire:model.live="category_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">Select category</option>
                    @foreach (Category::where('status', 'active')->orderBy('name')->get() as $category)
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
                    $purchaseUnit = \App\Models\Unit::find($purchase_unit_id);
                    $stockUnit = \App\Models\Unit::find($unit_id);
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
                @if (filled($measurement_type_id))
                    <span class="mt-1 block text-xs font-semibold text-slate-500">Filtered for {{ strtolower($this->measurementCode()) }} products.</span>
                @endif
                @error('selling_unit_id') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="flex items-center gap-3 self-end rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold dark:border-slate-800">
                <input type="checkbox" wire:model.live="uses_product_size" class="rounded border-slate-300 text-build-orange focus:ring-build-orange">
                Uses Product Size
            </label>

            @if ($this->showsProductSize())
                <label wire:transition class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                    Product Size
                    <input wire:model.live.debounce.300ms="product_size_search" class="mt-1 block w-full rounded-t-lg border border-b-0 border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="Search size, e.g. 2 × 4">
                    <select wire:model="product_size_id" class="block w-full rounded-b-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        <option value="">No size</option>
                        @foreach (\App\Models\ProductSize::query()->where('status', 'active')->when($product_size_search, fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$product_size_search}%")->orWhere('symbol', 'like', "%{$product_size_search}%")))->orderBy('symbol')->limit(50)->get() as $size)
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
                    @foreach (\App\Models\Branch::orderBy('name')->get() as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            </label>

            <x-form-input label="Brand" name="brand" wire:model="brand" />
            <x-form-input label="Model / Size" name="model_size" wire:model="model_size" />
            @include('livewire.products.base-unit-pricing-fields')
            <x-form-input label="Reorder Level" name="reorder_level" type="number" step="0.01" wire:model="reorder_level" required />

            @include('livewire.products.unit-conversions-fields')

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
                            $baseUnit = \App\Models\Unit::find($unit_id);
                            $sellingUnit = \App\Models\Unit::find($selling_unit_id);
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
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white" wire:loading.attr="disabled" wire:target="save,image_upload">
                    <span wire:loading.remove wire:target="save">Save Product</span>
                    <span wire:loading wire:target="save">Saving...</span>
                </button>
                <a href="{{ route('products.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Cancel</a>
            </div>
        </form>
    </x-card>
</div>
