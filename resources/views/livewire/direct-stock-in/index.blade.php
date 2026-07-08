<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Services\InventoryService;
use App\Support\InventorySettings;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state([
    'branch_id' => '',
    'product_id' => '',
    'quantity' => '1',
    'cost_price' => '',
    'selling_price' => '',
    'stock_location_id' => '',
    'reason' => 'Opening Stock',
    'notes' => '',
    'movement_date' => '',
]);

mount(function (InventoryService $inventory) {
    abort_unless(InventorySettings::directStockInAllowed(), 403);

    $this->branch_id = (string) InventorySettings::branchId();
    $this->stock_location_id = (string) InventorySettings::receivingLocation((int) $this->branch_id)->id;
    $this->movement_date = now()->toDateString();
});

rules(fn () => [
    'branch_id' => ['required', 'exists:branches,id'],
    'product_id' => ['required', 'exists:products,id'],
    'quantity' => ['required', 'numeric', 'gt:0'],
    'cost_price' => ['required', 'numeric', 'min:0'],
    'selling_price' => ['nullable', 'numeric', 'min:0'],
    'stock_location_id' => ['required', Rule::exists('stock_locations', 'id')],
    'reason' => ['required', Rule::in(['Opening Stock', 'Direct Purchase', 'Manual Entry', 'Stock Correction', 'Other'])],
    'notes' => ['nullable', 'string', 'max:1000'],
    'movement_date' => ['required', 'date'],
]);

$canUpdateSellingPrice = fn (): bool => auth()->user()?->hasAnyRole(['Super Admin', 'Admin']) ?? false;

$updatedProductId = function (string $value) {
    $this->selectProduct($value);
};

$selectProduct = function (string $productId) {
    $product = $productId ? Product::query()->find($productId) : null;

    $this->product_id = $product ? (string) $product->id : '';
    $this->cost_price = $product ? (string) $product->buying_price : '';
    $this->selling_price = $product ? (string) $product->selling_price : '';

    $this->dispatch('money-input-updated', model: 'cost_price', value: $this->cost_price);
    $this->dispatch('money-input-updated', model: 'selling_price', value: $this->selling_price);
};

$save = function (InventoryService $inventory) {
    abort_unless(InventorySettings::directStockInAllowed(), 403);

    $this->resetErrorBag();

    try {
        $data = $this->validate();
    } catch (ValidationException $exception) {
        session()->flash('error', $exception->validator->errors()->first() ?: 'Please fix the validation errors and try again.');

        throw $exception;
    }

    if (! $this->canUpdateSellingPrice()) {
        unset($data['selling_price']);
    }

    if (! InventorySettings::warehouseEnabled()) {
        $data['stock_location_id'] = InventorySettings::receivingLocation((int) $data['branch_id'])->id;
    }

    try {
        $inventory->directStockIn($data, auth()->id());
    } catch (ValidationException $exception) {
        session()->flash('error', $exception->validator->errors()->first() ?: 'Direct stock in could not be saved.');

        throw $exception;
    } catch (\Throwable $exception) {
        report($exception);

        $this->addError('save', 'Direct stock in could not be saved: '.$exception->getMessage());
        session()->flash('error', 'Direct stock in could not be saved: '.$exception->getMessage());

        return;
    }

    $this->reset(['product_id', 'quantity', 'cost_price', 'selling_price', 'notes']);
    $this->quantity = '1';
    $this->reason = 'Opening Stock';
    $this->movement_date = now()->toDateString();
    $this->stock_location_id = (string) InventorySettings::receivingLocation((int) $this->branch_id)->id;
    $this->dispatch('money-input-updated', model: 'cost_price', value: '');
    $this->dispatch('money-input-updated', model: 'selling_price', value: '');
    $this->resetPage();

    session()->flash('success', 'Direct stock in saved.');
};

?>

<div>
    <x-page-header title="Direct Stock In" description="Add stock directly without supplier or purchase order." :breadcrumbs="['Dashboard' => route('dashboard'), 'Direct Stock In' => null]" />

    @php
        $canUpdateSellingPrice = $this->canUpdateSellingPrice();
        $selectedProduct = filled($product_id) ? Product::query()->find($product_id) : null;
        $costPriceValue = filled($cost_price)
            ? $cost_price
            : ($selectedProduct?->buying_price ?? '');
        $sellingPriceValue = $canUpdateSellingPrice && filled($selling_price)
            ? $selling_price
            : ($selectedProduct?->selling_price ?? '');
        $productSelectOptions = [
            'placeholder' => 'Search or select product',
            'hasSearch' => true,
            'minSearchLength' => 0,
            'searchPlaceholder' => 'Search product by name or SKU',
            'searchNoResultText' => 'No product found',
            'optionAllowEmptyOption' => true,
            'toggleClasses' => 'relative py-2.5 ps-3 pe-9 flex w-full cursor-pointer rounded-lg border border-slate-200 bg-white text-start text-sm text-slate-800 shadow-sm outline-none transition before:absolute before:inset-0 before:z-1 focus:border-cyan-500 focus:ring-4 focus:ring-cyan-500/20 disabled:pointer-events-none disabled:opacity-60 dark:border-slate-700 dark:bg-navy-950 dark:text-white dark:focus:border-cyan-400',
            'dropdownClasses' => 'z-[80] mt-2 max-h-72 w-full overflow-hidden overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-xl dark:border-slate-700 dark:bg-slate-900',
            'optionClasses' => 'cursor-pointer rounded-lg px-3 py-2 text-sm text-slate-800 hover:bg-cyan-50 focus:bg-cyan-50 hs-selected:bg-cyan-500 hs-selected:text-white dark:text-slate-100 dark:hover:bg-cyan-500/10 dark:focus:bg-cyan-500/10 dark:hs-selected:bg-cyan-500',
            'searchClasses' => 'block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 outline-none ring-cyan-500/20 placeholder:text-slate-400 focus:border-cyan-500 focus:ring-4 dark:border-slate-700 dark:bg-slate-950 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-cyan-400',
            'searchWrapperClasses' => 'sticky top-0 z-10 bg-white p-1 dark:bg-slate-900',
            'dropdownScope' => 'parent',
        ];
    @endphp

    <div class="grid gap-6 xl:grid-cols-[420px_1fr]">
        <x-card title="Stock Entry" description="This creates a positive stock movement. Product quantity is calculated from movements.">
            <form wire:submit.prevent="save" class="space-y-4">
                @if (session('success'))
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">
                        {{ session('success') }}
                    </div>
                @endif

                @if (session('error'))
                    <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
                        {{ session('error') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
                        <p class="font-black">Please fix these errors:</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <label class="block text-sm font-bold">Product
                    <select
                        wire:model.live="product_id"
                        wire:change="selectProduct($event.target.value)"
                        wire:key="direct-stock-in-product-select"
                        data-hs-select='@json($productSelectOptions)'
                        class="hidden"
                    >
                        <option value="" @selected(blank($product_id))>Select product</option>
                        @foreach (Product::where('status', 'active')->orderBy('name')->get() as $product)
                            <option value="{{ $product->id }}" @selected((string) $product_id === (string) $product->id)>{{ $product->name }} / {{ $product->sku }}</option>
                        @endforeach
                    </select>
                    @error('product_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-form-input label="Quantity" name="quantity" type="number" step="0.01" wire:model="quantity" required />
                    <x-form-input label="Movement Date" name="movement_date" type="date" wire:model="movement_date" required />
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm font-bold">
                        Buying Price <span class="text-red-500">*</span>
                        <span data-money-field wire:ignore class="block" wire:key="direct-stock-in-cost-price-{{ $product_id ?: 'empty' }}-{{ $costPriceValue }}">
                            <input type="text" inputmode="decimal" data-money-display class="erp-input mt-1">
                            <input type="hidden" data-money-value value="{{ $costPriceValue }}" wire:model.live="cost_price">
                        </span>
                        @error('cost_price') <span class="erp-error">{{ $message }}</span> @enderror
                    </label>
                    @if ($canUpdateSellingPrice)
                        <label class="block text-sm font-bold">
                            Selling Price
                            <span data-money-field wire:ignore class="block" wire:key="direct-stock-in-selling-price-{{ $product_id ?: 'empty' }}-{{ $sellingPriceValue }}">
                                <input type="text" inputmode="decimal" data-money-display class="erp-input mt-1">
                                <input type="hidden" data-money-value value="{{ $sellingPriceValue }}" wire:model.live="selling_price">
                            </span>
                            @error('selling_price') <span class="erp-error">{{ $message }}</span> @enderror
                        </label>
                    @else
                        <label class="block text-sm font-bold">
                            Selling Price
                            <div class="mt-1 min-h-10 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
                                {{ filled($sellingPriceValue) ? 'TZS '.number_format((float) $sellingPriceValue, 2) : 'Select product' }}
                            </div>
                        </label>
                    @endif
                </div>

                @if (InventorySettings::warehouseEnabled())
                    <label class="block text-sm font-bold">Stock Location
                        <select wire:model="stock_location_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                            @foreach (StockLocation::where('branch_id', $branch_id)->where('status', 'active')->orderBy('type')->get() as $location)
                                <option value="{{ $location->id }}">{{ $location->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @else
                    <x-alert type="info">Warehouse is disabled. Stock will enter Dispensing Area automatically.</x-alert>
                @endif

                <label class="block text-sm font-bold">Reason
                    <select wire:model="reason" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        @foreach (['Opening Stock', 'Direct Purchase', 'Manual Entry', 'Stock Correction', 'Other'] as $reasonOption)
                            <option value="{{ $reasonOption }}">{{ $reasonOption }}</option>
                        @endforeach
                    </select>
                </label>

                <x-form-textarea label="Notes" name="notes" wire:model="notes" />

                <button type="submit" wire:loading.attr="disabled" wire:target="save" class="w-full rounded-xl bg-cyan-500 px-4 py-3 text-sm font-black text-white shadow-lg shadow-cyan-500/20 disabled:cursor-not-allowed disabled:opacity-60">
                    <span wire:loading.remove wire:target="save">Save Direct Stock In</span>
                    <span wire:loading wire:target="save">Saving...</span>
                </button>
            </form>
        </x-card>

        <x-card title="Recent Direct Stock In">
            @php
                $movements = StockMovement::query()
                    ->with(['product', 'stockLocation', 'creator'])
                    ->where('movement_type', 'direct_stock_in')
                    ->latest('movement_date')
                    ->paginate(12);
            @endphp
            <x-table :headers="['Date', 'Product', 'Location', 'Qty', 'Cost', 'Reason', 'Created By']">
                @forelse ($movements as $movement)
                    <tr>
                        <td class="px-4 py-3">{{ $movement->movement_date?->format('d M Y') }}</td>
                        <td class="px-4 py-3 font-bold">{{ $movement->product?->name }}</td>
                        <td class="px-4 py-3">{{ $movement->stockLocation?->name }}</td>
                        <td class="px-4 py-3 font-bold">{{ number_format((float) $movement->quantity, 2) }}</td>
                        <td class="px-4 py-3">TZS {{ number_format((float) $movement->unit_cost, 2) }}</td>
                        <td class="px-4 py-3">{{ str($movement->notes)->before(' - ') }}</td>
                        <td class="px-4 py-3">{{ $movement->creator?->name }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-sm text-slate-500">No direct stock entries found.</td></tr>
                @endforelse
            </x-table>
            <div class="mt-4">{{ $movements->links() }}</div>
        </x-card>
    </div>
</div>
