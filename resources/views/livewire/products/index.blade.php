<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\Unit;
use App\Services\WhatsAppNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state([
    'search' => '',
    'statusFilter' => '',
    'branchFilter' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''),
    'categoryFilter' => '',
    'created_from' => '',
    'created_to' => '',
    'editing_product_id' => null,
    'deleting_product_id' => null,
    'branch_id' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''),
    'category_id' => '',
    'unit_id' => '',
    'product_size_id' => '',
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

mount(function () {
    $this->search = request('search', $this->search);
    $this->statusFilter = request('statusFilter', $this->statusFilter);
    $this->branchFilter = (string) (\App\Support\BranchAccess::resolve(auth()->user(), request('branchFilter', $this->branchFilter) ? (int) request('branchFilter', $this->branchFilter) : null) ?? '');
    $this->categoryFilter = request('categoryFilter', $this->categoryFilter);
    $this->created_from = request('created_from', $this->created_from);
    $this->created_to = request('created_to', $this->created_to);
});

$canManage = fn () => auth()->user()->canAny(['products.create', 'products.edit', 'products.delete']);
$canViewBuyingPrice = fn () => auth()->user()->can('products.view_buying_price');

$resetProductForm = function () {
    $this->reset([
        'editing_product_id',
        'branch_id',
        'category_id',
        'unit_id',
        'product_size_id',
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
    abort_unless(auth()->user()->can('products.create'), 403);

    $this->resetProductForm();
    $this->dispatch('open-modal', 'product-form');
};

$openEditProduct = function (int $productId) {
    abort_unless(auth()->user()->can('products.edit'), 403);

    $product = Product::findOrFail($productId);

    $this->editing_product_id = $product->id;
    $this->branch_id = (string) $product->branch_id;
    $this->category_id = (string) $product->category_id;
    $this->unit_id = (string) $product->unit_id;
    $this->product_size_id = (string) $product->product_size_id;
    $this->name = $product->name;
    $this->sku = $product->sku;
    $this->barcode = $product->barcode;
    $this->brand = $product->brand;
    $this->model_size = $product->model_size;
    $this->image = $product->image;
    $this->buying_price = $this->canViewBuyingPrice() ? (string) $product->buying_price : '';
    $this->selling_price = (string) $product->selling_price;
    $this->wholesale_price = $product->wholesale_price === null ? '' : (string) $product->wholesale_price;
    $this->reorder_level = (string) $product->reorder_level;
    $this->taxable = (bool) $product->taxable;
    $this->status = $product->status;
    $this->resetErrorBag();

    $this->dispatch('open-modal', 'product-form');
};

$saveProduct = function () {
    abort_unless(auth()->user()->can($this->editing_product_id ? 'products.edit' : 'products.create'), 403);

    $validated = $this->validate([
        'branch_id' => ['nullable', 'exists:branches,id'],
        'category_id' => ['required', 'exists:categories,id'],
        'unit_id' => ['required', 'exists:units,id'],
        'product_size_id' => ['nullable', 'exists:product_sizes,id'],
        'name' => ['required', 'string', 'max:255'],
        'sku' => ['nullable', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($this->editing_product_id)],
        'barcode' => ['nullable', 'string', 'max:100', Rule::unique('products', 'barcode')->ignore($this->editing_product_id)],
        'brand' => ['nullable', 'string', 'max:255'],
        'model_size' => ['nullable', 'string', 'max:255'],
        'image' => ['nullable', 'string', 'max:255'],
        'buying_price' => [auth()->user()->can('products.edit_buying_price') ? 'required' : 'nullable', 'numeric', 'min:0'],
        'selling_price' => ['required', 'numeric', 'min:0'],
        'wholesale_price' => ['nullable', 'numeric', 'min:0'],
        'reorder_level' => ['required', 'numeric', 'min:0'],
        'taxable' => ['boolean'],
        'status' => ['required', 'in:active,inactive'],
    ]);

    $validated['branch_id'] = $validated['branch_id'] ?: null;
    $validated['product_size_id'] = $validated['product_size_id'] ?: null;
    $validated['sku'] = $validated['sku'] ?: null;
    $validated['barcode'] = $validated['barcode'] ?: null;
    $validated['wholesale_price'] = $validated['wholesale_price'] === '' ? null : $validated['wholesale_price'];

    if (! auth()->user()->can('products.edit_buying_price')) {
        if ($this->editing_product_id) {
            unset($validated['buying_price']);
        } else {
            $validated['buying_price'] = 0;
        }
    }

    $product = $this->editing_product_id
        ? Product::findOrFail($this->editing_product_id)
        : new Product();

    $product->fill($validated)->save();

    session()->flash('success', $this->editing_product_id ? __('application.product_updated_successfully_8cf048a1') : __('application.product_created_successfully_ec13ac3e'));
    $this->resetProductForm();
    $this->dispatch('close-modal', 'product-form');
};

$toggleStatus = function (int $productId) {
    abort_unless(auth()->user()->can('products.edit'), 403);

    $product = Product::findOrFail($productId);
    $product->update(['status' => $product->status === 'active' ? 'inactive' : 'active']);

    session()->flash('success', __('application.product_status_updated_78aa0124'));
};

$queueProductDeletedNotification = function (Product $product): void {
    $user = auth()->user();

    $company = $product->company()->withoutGlobalScopes()->first();

    if (! $company) {
        return;
    }

    $setting = \App\Models\CompanyWhatsAppSetting::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)
        ->where('company_id', $company->id)
        ->first();

    $language = $setting?->notificationLanguage() ?? 'en';

    $productName = $product->displayName();
    $sku = $product->sku ?: '-';
    $branchName = $product->branch?->name ?: '-';
    $deletedBy = $user?->name ?: 'System';
    $deletedAt = now()->locale(app()->getLocale())->translatedFormat('d M Y h:i A');

    $message = app(\App\Services\WhatsAppMessageFactory::class)->productDeleted($product, $company, $user);

    app(WhatsAppNotificationService::class)->afterCommit(
        fn (WhatsAppNotificationService $notifications) =>
            $notifications->queueForRecipients(
                company: $company,
                category: 'security',
                notificationType: 'product_deleted',
                eventKey: 'product_deleted:'.$product->id,
                message: $message,
                branchId: $product->branch_id,
                metadata: [
                    'product_id' => $product->id,
                    'product_name' => $productName,
                    'sku' => $product->sku,
                    'deleted_by_user_id' => $user?->id,
                    'deleted_by_name' => $deletedBy,
                    'language' => $language,
                ],
            )
    );
};

$deleteProduct = function (int $productId) use ($queueProductDeletedNotification) {
    abort_unless(auth()->user()->can('products.delete'), 403);

    $product = Product::with(['branch'])->findOrFail($productId);

    DB::transaction(function () use ($product, $queueProductDeletedNotification): void {
        $queueProductDeletedNotification($product);
        $product->delete();
    });

    session()->flash('success', __('application.product_deleted_7aef31e5'));
};

$confirmDeleteProduct = function (int $productId) {
    abort_unless(auth()->user()->can('products.delete'), 403);

    $this->deleting_product_id = $productId;
    $this->dispatch('open-modal', 'delete-product');
};

$deleteConfirmedProduct = function () use ($queueProductDeletedNotification) {
    abort_unless(auth()->user()->can('products.delete'), 403);

    $product = Product::with(['branch'])->findOrFail($this->deleting_product_id);

    DB::transaction(function () use ($product, $queueProductDeletedNotification): void {
        $queueProductDeletedNotification($product);
        $product->delete();
    });

    $this->deleting_product_id = null;

    session()->flash('success', __('application.product_deleted_7aef31e5'));
    $this->dispatch('close-modal', 'delete-product');
};

?>

<div>
    <x-page-header
        title="{{ __('application.products_fe0a091f') }}"
        description="{{ __('application.product_master_data_only_stock_quantities_will_come_from_stock_movements_in_b6bd163a') }}"
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Products' => null]"
    >
        <x-export-actions export="tables.products" :params="compact('search', 'statusFilter', 'branchFilter', 'categoryFilter', 'created_from', 'created_to')" />
        @if ($this->canManage())
            <a href="{{ route('products.create') }}" wire:navigate data-tour="add-product" class="erp-btn-primary">{{ __('application.create_product_a0b51f0d') }}</a>
        @endif
    </x-page-header>

    <x-help-tip class="mb-4">
        {{ __('application.add_products_before_recording_purchases_sku_is_a_unique_product_identifier__13a4bd15') }}
    </x-help-tip>

    <x-card>
        <div data-tour="product-filters" class="mb-4 grid gap-3 md:grid-cols-5">
            <input data-tour="product-search" wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5 md:col-span-2" placeholder="{{ __('application.search_products_sku_barcode_f06e6cb7') }}">
            <select wire:model.live="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ __('application.all_statuses_6405179d') }}</option>
                <option value="active">{{ __('application.active_a733b809') }}</option>
                <option value="inactive">{{ __('application.inactive_09af574c') }}</option>
            </select>
            <select wire:model.live="branchFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())>
                <option value="">{{ __('application.all_branches_25c23746') }}</option>
                @foreach (Branch::orderBy('name')->get() as $branch)
                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="categoryFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                <option value="">{{ __('application.all_categories_060be00f') }}</option>
                @foreach (Category::orderBy('name')->get() as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
        </div>

        @php
            $products = Product::query()
                ->with(['branch', 'category', 'measurementType', 'purchaseUnit', 'unit', 'sellingUnit', 'size'])
                ->when($search, fn ($query) => $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhereHas('size', fn ($size) => $size->where('name', 'like', "%{$search}%")->orWhere('symbol', 'like', "%{$search}%"))))
                ->when($statusFilter, fn ($query) => $query->where('status', $statusFilter))
                ->when($branchFilter, fn ($query) => $query->where('branch_id', $branchFilter))
                ->when($categoryFilter, fn ($query) => $query->where('category_id', $categoryFilter))
                ->when($created_from, fn ($query) => $query->whereDate('created_at', '>=', $created_from))
                ->when($created_to, fn ($query) => $query->whereDate('created_at', '<=', $created_to))
                ->latest()
                ->paginate(10);
            $hasProductSizes = $products->getCollection()->contains(fn (Product $product) => filled($product->sizeLabel()));
            $productHeaders = ['Product', 'Measurement Type'];
            if ($hasProductSizes) {
                $productHeaders[] = 'Size';
            }
            array_push($productHeaders, 'SKU', 'Barcode', 'Category', 'Purchase Unit', 'Stock Unit', 'Selling Unit', 'Stock');
            if ($this->canViewBuyingPrice()) {
                $productHeaders[] = 'Buying';
            }
            array_push($productHeaders, 'Selling', 'Reorder', 'Status', 'Actions');
        @endphp

        <x-table data-tour="products-list" :headers="$productHeaders">
            @forelse ($products as $product)
                <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                    <td class="px-4 py-3">
                        <div class="flex min-w-56 items-center gap-3">
                            <x-product-image :product="$product" class="h-12 w-12" />
                            <div>
                                <p class="font-black">{{ $product->displayName() }}</p>
                                <p class="text-xs text-slate-500">{{ $product->brand ?? __('terminology.no_brand_4b46a784') }} {{ $product->model_size ? '/ '.$product->model_size : '' }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 font-bold">{{ $product->measurementType?->name ?? str($product->measurementCode())->title() }}</td>
                    @if ($hasProductSizes)
                        <td class="px-4 py-3 font-bold">{{ $product->sizeLabel() ?: '-' }}</td>
                    @endif
                    <td class="px-4 py-3 font-mono text-xs">{{ $product->sku ?: '-' }}</td>
                    <td class="px-4 py-3 font-mono text-xs">{{ $product->barcode ?? '-' }}</td>
                    <td class="px-4 py-3">{{ $product->category?->name }}</td>
                    <td class="px-4 py-3">{{ $product->purchaseUnit?->short_name }}</td>
                    <td class="px-4 py-3">{{ $product->unit?->short_name }}</td>
                    <td class="px-4 py-3">{{ $product->sellingUnit?->short_name }}</td>
                    <td class="px-4 py-3 font-bold">
                        {{ \App\Support\NumberFormatter::quantity(app(\App\Services\InventoryService::class)->getProductTotalStock($product->id, (int) ($product->branch_id ?: auth()->user()->branch_id ?: \App\Models\Branch::where('code', 'MAIN')->value('id')))) }}
                    </td>
                    @if ($this->canViewBuyingPrice())
                        <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money($product->buying_price) }}</td>
                    @endif
                    <td class="px-4 py-3 font-bold">TZS {{ \App\Support\NumberFormatter::money($product->selling_price) }}</td>
                    <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($product->reorder_level) }}</td>
                    <td class="px-4 py-3"><span class="{{ $product->status === 'active' ? 'badge-success' : 'badge-warning' }}">{{ \App\Support\UiText::status($product->status) }}</span></td>
                    <td class="px-4 py-3">
                        @if ($this->canManage())
                            <div data-tour="product-actions" class="hs-dropdown relative inline-flex [--placement:bottom-end] [--strategy:fixed]">
                                <button type="button" class="hs-dropdown-toggle rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
                                    {{ __('application.actions_c3cd636a') }}
                                </button>
                                <div class="hs-dropdown-menu z-[90] mt-2 hidden min-w-40 rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg dark:border-slate-700 dark:bg-slate-900" role="menu">
                                    <a href="{{ route('products.edit', $product) }}" wire:navigate class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-white/5">{{ __('application.edit_5301648d') }}</a>
                                    <button type="button" onclick="this.closest('.hs-dropdown')?.querySelector('.hs-dropdown-toggle')?.click()" wire:click="toggleStatus({{ $product->id }})" class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-white/5">{{ $product->status === 'active' ? __('application.deactivate_d65ded94') : __('application.activate_92ef0832') }}</button>
                                    <button type="button" onclick="this.closest('.hs-dropdown')?.querySelector('.hs-dropdown-toggle')?.click()" wire:click="confirmDeleteProduct({{ $product->id }})" class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10">{{ __('application.delete_f6fdbe48') }}</button>
                                </div>
                            </div>
                        @else
                            <span class="text-xs text-slate-500">{{ __('application.view_only_5559dd04') }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="13" class="px-4 py-8 text-center text-slate-500">{{ __('application.no_products_found_17a346fb') }}</td></tr>
            @endforelse
        </x-table>

        <div class="mt-4">{{ $products->links() }}</div>
    </x-card>

    <x-modal name="product-form" maxWidth="2xl">
        <form wire:submit="saveProduct" wire:key="product-form-{{ $editing_product_id ?: 'create' }}" class="flex min-h-full flex-col sm:max-h-[calc(100vh-3rem)]">
            <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ $editing_product_id ? __('application.edit_product_f8f4e0d8') : __('application.add_product_ee611e53') }}</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('application.create_and_update_product_master_data_without_leaving_this_page_ffcd0528') }}</p>
                </div>
                <button type="button" x-on:click="$dispatch('close-modal', 'product-form')" class="erp-btn-secondary px-2 py-1" wire:loading.attr="disabled">{{ __('application.close_bbfa773e') }}</button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-5">
                <div class="grid gap-4 md:grid-cols-2">
                    <x-form-input label="{{ __('application.product_name_06bc6513') }}" name="name" wire:model="name" required />
                    <x-form-input label="{{ __('application.sku_d59192b9') }}" name="sku" wire:model="sku" />
                    <x-form-input label="{{ __('application.barcode_8e54dc53') }}" name="barcode" wire:model="barcode" />

                    <x-form-select label="{{ __('application.category_a3c686e7') }}" name="category_id" wire:model="category_id" required>
                        <option value="">{{ __('application.select_category_a2a5cafe') }}</option>
                        @foreach (Category::where('status', 'active')->orderBy('name')->get() as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </x-form-select>

                    <x-form-select label="{{ __('application.unit_f6b935ab') }}" name="unit_id" wire:model="unit_id" required>
                        <option value="">{{ __('application.select_unit_bc6df6de') }}</option>
                        @foreach (Unit::where('status', 'active')->orderBy('name')->get() as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->name }} / {{ $unit->short_name }}</option>
                        @endforeach
                    </x-form-select>

                    <x-form-select label="{{ __('application.product_size_385b50f6') }}" name="product_size_id" wire:model="product_size_id">
                        <option value="">{{ __('application.no_size_595cc0ee') }}</option>
                        @foreach (ProductSize::where('status', 'active')->orderBy('symbol')->get() as $size)
                            <option value="{{ $size->id }}">{{ $size->label() }}</option>
                        @endforeach
                    </x-form-select>

                    <x-form-select label="{{ __('application.branch_1627510b') }}" name="branch_id" wire:model="branch_id" :disabled="\App\Support\BranchAccess::restricted()">
                        <option value="">{{ __('application.global_product_8bda637d') }}</option>
                        @foreach (Branch::orderBy('name')->get() as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </x-form-select>

                    <x-form-input label="{{ __('application.brand_62b4aa57') }}" name="brand" wire:model="brand" />
                    <x-form-input label="{{ __('application.model_size_995b1380') }}" name="model_size" wire:model="model_size" />
                    @if (auth()->user()->can('products.view_buying_price'))
                        @if (auth()->user()->can('products.edit_buying_price'))
                            <x-money-input label="{{ __('application.buying_price_a46c40be') }}" name="buying_price" value="{{ $buying_price }}" wire:model="buying_price" required />
                        @else
                            <div><span class="text-xs text-slate-500">{{ __('application.buying_price_a46c40be') }}</span><p class="font-bold">TZS {{ \App\Support\NumberFormatter::money($buying_price) }}</p></div>
                        @endif
                    @endif
                    <x-money-input label="{{ __('application.selling_price_897611ec') }}" name="selling_price" value="{{ $selling_price }}" wire:model="selling_price" required />
                    <x-money-input label="{{ __('application.wholesale_price_7ecac3d9') }}" name="wholesale_price" value="{{ $wholesale_price }}" wire:model="wholesale_price" />
                    <x-form-input label="{{ __('application.reorder_level_59ef31e3') }}" name="reorder_level" type="number" step="0.01" wire:model="reorder_level" required />

                    <x-form-select label="{{ __('application.status_bae7d5be') }}" name="status" wire:model="status" required>
                        <option value="active">{{ __('application.active_a733b809') }}</option>
                        <option value="inactive">{{ __('application.inactive_09af574c') }}</option>
                    </x-form-select>

                    <label class="flex items-center gap-3 self-end rounded-xl border border-slate-200 px-4 py-3 text-sm font-medium dark:border-slate-700">
                        <input type="checkbox" wire:model="taxable" class="rounded border-slate-300 text-build-orange focus:ring-build-orange dark:border-slate-700 dark:bg-slate-950">
                        {{ __('application.taxable_product_db5954c8') }}
                    </label>
                </div>
            </div>

            <div class="flex flex-col-reverse gap-2 border-t border-slate-200 px-5 py-4 dark:border-slate-700 sm:flex-row sm:justify-end">
                <button type="button" x-on:click="$dispatch('close-modal', 'product-form')" class="erp-btn-secondary" wire:loading.attr="disabled">{{ __('application.cancel_77dfd213') }}</button>
                <button class="erp-btn-primary" wire:loading.attr="disabled" wire:target="saveProduct">
                    <span wire:loading.remove wire:target="saveProduct">{{ $editing_product_id ? __('application.update_product_ab1b8e06') : __('application.save_product_1076e8ef') }}</span>
                    <span wire:loading wire:target="saveProduct">{{ __('application.saving_ae7e8875') }}</span>
                </button>
            </div>
        </form>
    </x-modal>

    <x-modal name="delete-product" maxWidth="md" :closeOnBackdrop="false">
        @php($deletingProduct = $deleting_product_id ? Product::find($deleting_product_id) : null)
        <div class="p-5">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ __('application.confirm_delete_59e5bdd4') }}</h2>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                {{ __('application.are_you_sure_you_want_to_delete_6c257b92') }} {{ $deletingProduct?->name ? '"'.$deletingProduct->name.'"' : 'this record' }}?
            </p>

            <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" x-on:click="$dispatch('close-modal', 'delete-product')" class="erp-btn-secondary" wire:loading.attr="disabled">{{ __('application.cancel_77dfd213') }}</button>
                <button type="button" wire:click="deleteConfirmedProduct" wire:loading.attr="disabled" wire:target="deleteConfirmedProduct" class="inline-flex items-center justify-center rounded-lg bg-red-500 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-600 disabled:pointer-events-none disabled:opacity-50">
                    <span wire:loading.remove wire:target="deleteConfirmedProduct">{{ __('application.delete_f6fdbe48') }}</span>
                    <span wire:loading wire:target="deleteConfirmedProduct">{{ __('application.deleting_e16cac65') }}</span>
                </button>
            </div>
        </div>
    </x-modal>
</div>
