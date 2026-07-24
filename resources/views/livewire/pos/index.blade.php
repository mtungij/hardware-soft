<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockLocation;
use App\Services\InventoryService;
use App\Support\InventorySettings;
use Illuminate\Validation\ValidationException;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'branch_id' => '',
    'stock_location_id' => '',
    'pending_stock_location_id' => '',
    'search' => '',
    'barcode' => '',
    'customer_id' => '',
    'sale_type' => 'retail',
    'cart' => [],
    'payments' => [['payment_method' => 'cash', 'amount' => '0', 'reference_number' => '']],
    'notes' => '',
    'quick_customer_branch_id' => '',
    'quick_customer_name' => '',
    'quick_customer_phone' => '',
    'quick_customer_email' => '',
    'quick_customer_address' => '',
    'quick_customer_region' => '',
    'quick_customer_district' => '',
    'quick_customer_type' => 'credit',
    'quick_customer_opening_balance' => '0',
    'quick_customer_status' => 'active',
    'unassigned_credit_confirmed' => false,
    'temporary_customer_name' => '',
    'temporary_customer_phone' => '',
    'project_name' => '',
    'vehicle_number' => '',
    'expected_payment_date' => '',
    'credit_notes' => '',
    'auto_payment_amount' => '0',
    'payment_amount_manually_edited' => false,
]);

mount(function (InventoryService $inventory) {
    $this->branch_id = (string) (auth()->user()->branch_id ?: Branch::where('code', 'MAIN')->value('id'));
    $allowedLocations = collect(InventorySettings::allowedSaleLocationsForUser(auth()->user(), (int) $this->branch_id));
    $setting = InventorySettings::current();

    if ($allowedLocations->count() === 1 || ($setting->inventory_mode ?? 'multi_location') === 'single_location') {
        $this->stock_location_id = (string) ($allowedLocations->first()?->id
            ?? $inventory->getDispensingLocation((int) $this->branch_id)->id);
    }

    $this->quick_customer_branch_id = $this->branch_id;
});

$canCreditSale = fn () => auth()->user()->can('create credit sales') || auth()->user()->hasAnyRole(['Super Admin', 'Admin', 'Manager']);
$canCreateUnassignedCreditSale = fn () => auth()->user()->can('create unassigned credit sales') || auth()->user()->hasAnyRole(['Super Admin', 'Admin', 'Manager']);

$allowedSaleLocations = fn () => collect(InventorySettings::allowedSaleLocationsForUser(auth()->user(), (int) $this->branch_id));

$currentSaleLocation = function () {
    $locations = $this->allowedSaleLocations();
    return $locations->firstWhere('id', (int) $this->stock_location_id);
};

$hasSelectedSaleLocation = fn () => filled($this->stock_location_id) && (bool) $this->currentSaleLocation();

$applyStockLocation = function ($locationId) {
    $this->stock_location_id = filled($locationId) ? (string) $locationId : '';
    $this->pending_stock_location_id = '';
    $this->search = '';
    $this->barcode = '';
    $this->resetErrorBag(['stock_location_id', 'cart']);
};

$requestStockLocationChange = function ($locationId) {
    $locationId = filled($locationId) ? (string) $locationId : '';

    if ($locationId === (string) $this->stock_location_id) {
        return;
    }

    if ($this->cart !== []) {
        $this->pending_stock_location_id = $locationId;
        $this->dispatch('open-modal', 'confirm-selling-location-change');

        return;
    }

    $this->applyStockLocation($locationId);
};

$confirmStockLocationChange = function () {
    $locationId = $this->pending_stock_location_id;
    $this->cart = [];
    $this->syncDefaultPaymentAmount();
    $this->unassigned_credit_confirmed = false;
    $this->applyStockLocation($locationId);
    $this->dispatch('close-modal', 'confirm-selling-location-change');
};

$cancelStockLocationChange = function () {
    $this->pending_stock_location_id = '';
    $this->dispatch('close-modal', 'confirm-selling-location-change');
};

$priceForProduct = function (Product $product): string {
    if ($this->sale_type === 'wholesale') {
        return filled($product->wholesale_price) ? (string) $product->wholesale_price : '';
    }

    return (string) $product->selling_price;
};

$updatedSaleType = function () {
    foreach ($this->cart as $index => $item) {
        $product = Product::query()->find($item['product_id'] ?? null);

        if (! $product) {
            continue;
        }

        $unitPrice = $this->priceForProduct($product);

        if ($unitPrice === '') {
            $this->cart[$index]['unit_price'] = '0';
            $this->cart[$index]['tax_amount'] = '0';
            $this->dispatch('money-input-updated', model: "cart.{$index}.unit_price", value: '0');
            $this->dispatch('money-input-updated', model: "cart.{$index}.tax_amount", value: '0');
            continue;
        }

        $this->cart[$index]['sale_type'] = $this->sale_type;
        $this->cart[$index]['unit_price'] = $unitPrice;
        $this->cart[$index]['tax_amount'] = $product->taxable ? (string) round((float) $unitPrice * 0.18, 2) : '0';
        $this->dispatch('money-input-updated', model: "cart.{$index}.unit_price", value: $this->cart[$index]['unit_price']);
        $this->dispatch('money-input-updated', model: "cart.{$index}.tax_amount", value: $this->cart[$index]['tax_amount']);
    }

    $this->syncDefaultPaymentAmount();
};

$resetQuickCustomerForm = function () {
    $this->quick_customer_branch_id = $this->branch_id;
    $this->quick_customer_name = '';
    $this->quick_customer_phone = '';
    $this->quick_customer_email = '';
    $this->quick_customer_address = '';
    $this->quick_customer_region = '';
    $this->quick_customer_district = '';
    $this->quick_customer_type = 'credit';
    $this->quick_customer_opening_balance = '0';
    $this->quick_customer_status = 'active';
    $this->resetErrorBag();
};

$updatedQuickCustomerRegion = function () {
    $this->quick_customer_district = '';
};

$openQuickCustomerModal = function () {
    $this->resetQuickCustomerForm();
    $this->dispatch('open-modal', 'quick-customer');
};

$saveQuickCustomer = function () {
    $data = $this->validate([
        'quick_customer_branch_id' => ['nullable', 'exists:branches,id'],
        'quick_customer_name' => ['required', 'string', 'max:255'],
        'quick_customer_phone' => ['nullable', 'string', 'max:30'],
        'quick_customer_email' => ['nullable', 'email', 'max:255'],
        'quick_customer_address' => ['nullable', 'string', 'max:1000'],
        'quick_customer_region' => ['nullable', 'string', 'max:255'],
        'quick_customer_district' => ['nullable', 'string', 'max:255'],
        'quick_customer_type' => ['required', 'in:cash,credit,contractor,wholesale'],
        'quick_customer_opening_balance' => ['required', 'numeric', 'min:0'],
        'quick_customer_status' => ['required', 'in:active,inactive'],
    ]);

    $fallbackPhone = 'QUICK-'.str($data['quick_customer_name'])->slug('-')->limit(20, '').'-'.now()->format('His');

    $customer = Customer::create([
        'branch_id' => $data['quick_customer_branch_id'] ?: null,
        'name' => $data['quick_customer_name'],
        'phone' => $data['quick_customer_phone'] ?: $fallbackPhone,
        'email' => $data['quick_customer_email'] ?: null,
        'address' => $data['quick_customer_address'] ?: null,
        'region' => $data['quick_customer_region'] ?: null,
        'district' => $data['quick_customer_district'] ?: null,
        'customer_type' => $data['quick_customer_type'],
        'credit_limit' => 0,
        'opening_balance' => $data['quick_customer_opening_balance'],
        'balance_amount' => $data['quick_customer_opening_balance'],
        'status' => $data['quick_customer_status'],
    ]);

    $this->customer_id = (string) $customer->id;
    $this->resetQuickCustomerForm();
    $this->dispatch('close-modal', 'quick-customer');
    session()->flash('success', \App\Support\UiText::translate('Customer created and selected.'));
};

$syncDefaultPaymentAmount = function () {
    if (! isset($this->payments[0]) || count($this->payments) !== 1) {
        return;
    }

    $grandTotal = (float) $this->grandTotal();

    if ($this->payment_amount_manually_edited && (float) ($this->payments[0]['amount'] ?? 0) > $grandTotal) {
        return;
    }

    $this->payments[0]['amount'] = (string) $grandTotal;
    $this->auto_payment_amount = (string) $grandTotal;
    $this->dispatch('money-input-updated', model: 'payments.0.amount', value: $this->payments[0]['amount']);
};

$updatedPayments = function () {
    if (! isset($this->payments[0]) || count($this->payments) !== 1) {
        return;
    }

    $this->syncDefaultPaymentAmount();
};

$updatedCart = function () {
    $this->syncDefaultPaymentAmount();
};

$addProduct = function (int $productId) {
    if (! $this->hasSelectedSaleLocation()) {
        $this->addError('stock_location_id', \App\Support\UiText::translate('Select Selling Location'));

        return;
    }

    $product = Product::query()->with(['category', 'measurementType', 'unit', 'sellingUnit', 'size'])->findOrFail($productId);
    $supportsFractionalSales = $product->allowsDecimalQuantities();
    $conversionFactor = $product->saleConversionFactor();
    $baseStock = app(InventoryService::class)->getProductStock(
        $productId,
        (int) $this->currentSaleLocation()->id,
        (int) $this->branch_id,
    );
    $available = $baseStock * $conversionFactor;

    if ($available <= 0) {
        $this->addError('cart', \App\Support\UiText::translate('Product is out of stock in selected source.'));
        return;
    }

    $unitPrice = $this->priceForProduct($product);

    if ($unitPrice === '') {
        $this->addError('cart', \App\Support\UiText::translate('Wholesale price is not set for this product.'));
        return;
    }

    foreach ($this->cart as $index => $item) {
        if ((int) $item['product_id'] === $productId) {
            $step = $supportsFractionalSales ? max(0.0001, (float) ($product->quantity_step ?: 1)) : 1;
            $this->cart[$index]['quantity'] = (string) min($available, (float) $item['quantity'] + $step);
            $this->cart[$index]['sale_type'] = $this->sale_type;
            $this->cart[$index]['unit_price'] = $unitPrice;
            $this->syncDefaultPaymentAmount();

            return;
        }
    }

    $this->cart[] = [
        'product_id' => $product->id,
        'name' => $product->displayName(),
        'size' => $product->sizeLabel(),
        'sku' => $product->sku,
        'sale_type' => $this->sale_type,
        'quantity' => $supportsFractionalSales
            ? (string) ($product->minimum_sale_quantity ?: ($product->quantity_step ?: 1))
            : '1',
        'unit_price' => $unitPrice,
        'discount_amount' => '0',
        'tax_amount' => $product->taxable ? (string) round((float) $unitPrice * 0.18, 2) : '0',
        'selling_unit' => $product->sellingUnit?->short_name ?: $product->unit?->short_name,
        'base_unit' => $product->unit?->short_name,
        'conversion_factor' => (string) $conversionFactor,
        'measurement_type' => $product->measurementType?->name ?? str($product->measurementCode())->title()->toString(),
        'allow_fractional_sale' => $supportsFractionalSales,
        'minimum_sale_quantity' => $supportsFractionalSales ? (string) ($product->minimum_sale_quantity ?: 1) : '1',
        'quantity_step' => $supportsFractionalSales ? (string) ($product->quantity_step ?: 1) : '1',
    ];

    $this->syncDefaultPaymentAmount();
};

$addBarcode = function () {
    if (! $this->hasSelectedSaleLocation()) {
        $this->addError('stock_location_id', \App\Support\UiText::translate('Select Selling Location'));

        return;
    }

    $product = Product::where('barcode', $this->barcode)->first();
    if ($product) {
        $this->addProduct($product->id);
        $this->barcode = '';
    }
};

$removeItem = function (int $index) {
    unset($this->cart[$index]);
    $this->cart = array_values($this->cart);
    $this->syncDefaultPaymentAmount();
};

$addPayment = function () {
    $this->payments[] = ['payment_method' => 'cash', 'amount' => '0', 'reference_number' => ''];
};

$removePayment = function (int $index) {
    unset($this->payments[$index]);
    $this->payments = array_values($this->payments);
    $this->syncDefaultPaymentAmount();
};

$subtotal = fn () => collect($this->cart)->sum(function ($item) {
    $quantity = (float) ($item['quantity'] ?? 0);
    $unitPrice = (float) ($item['unit_price'] ?? 0);

    return $quantity * $unitPrice;
});

$discountTotal = fn () => collect($this->cart)->sum(function ($item) {
    $quantity = (float) ($item['quantity'] ?? 0);
    $discountPerUnit = (float) ($item['discount_amount'] ?? 0);

    return $quantity * $discountPerUnit;
});

$taxTotal = fn () => collect($this->cart)->sum(function ($item) {
    $quantity = (float) ($item['quantity'] ?? 0);
    $taxPerUnit = (float) ($item['tax_amount'] ?? 0);

    return $quantity * $taxPerUnit;
});

$grandTotal = fn () => max(
    0,
    $this->subtotal()
    - $this->discountTotal()
    + $this->taxTotal()
);

$paidTotal = fn () => collect($this->payments)
    ->reject(fn ($payment) => ($payment['payment_method'] ?? null) === 'credit')
    ->sum(fn ($payment) => (float) ($payment['amount'] ?? 0));

$usesCreditPayment = fn () => collect($this->payments)->contains(fn ($payment) => ($payment['payment_method'] ?? null) === 'credit');

$cancelUnassignedCreditWarning = function () {
    $this->unassigned_credit_confirmed = false;
    $this->dispatch('close-modal', 'unassigned-credit-warning');
};

$continueWithoutCustomer = function (InventoryService $inventory) {
    if (! (bool) (InventorySettings::current()->allow_credit_sale_without_customer ?? true) || ! $this->canCreateUnassignedCreditSale()) {
        $this->addError('customer_id', \App\Support\UiText::translate('Select a customer or create a customer before completing this credit sale.'));

        return;
    }

    $this->unassigned_credit_confirmed = true;
    $this->dispatch('close-modal', 'unassigned-credit-warning');
    $this->completeSale($inventory);
};

$selectCustomerFromWarning = function () {
    $this->dispatch('close-modal', 'unassigned-credit-warning');
};

$quickAddCustomerFromWarning = function () {
    $this->dispatch('close-modal', 'unassigned-credit-warning');
    $this->openQuickCustomerModal();
};

$completeSale = function (InventoryService $inventory) {
    $this->validate([
        'stock_location_id' => ['required', 'exists:stock_locations,id'],
        'customer_id' => ['nullable', 'exists:customers,id'],
        'cart' => ['required', 'array', 'min:1'],
        'cart.*.product_id' => ['required', 'exists:products,id'],
        'cart.*.sale_type' => ['required', 'in:retail,wholesale'],
        'cart.*.quantity' => ['required', 'numeric', 'gt:0'],
        'cart.*.unit_price' => ['required', 'numeric', 'min:0'],
        'cart.*.discount_amount' => ['required', 'numeric', 'min:0'],
        'cart.*.tax_amount' => ['required', 'numeric', 'min:0'],
        'payments' => ['required', 'array', 'min:1'],
        'payments.*.payment_method' => ['required', 'in:cash,mobile_money,bank,credit'],
        'payments.*.amount' => ['required', 'numeric', 'min:0'],
        'temporary_customer_name' => ['nullable', 'string', 'max:255'],
        'temporary_customer_phone' => ['nullable', 'string', 'max:30'],
        'project_name' => ['nullable', 'string', 'max:255'],
        'vehicle_number' => ['nullable', 'string', 'max:255'],
        'expected_payment_date' => ['nullable', 'date'],
        'credit_notes' => ['nullable', 'string', 'max:2000'],
    ]);

    foreach ($this->cart as $item) {
        $unitPrice = (float) ($item['unit_price'] ?? 0);
        $discountPerUnit = (float) ($item['discount_amount'] ?? 0);

        if ($unitPrice > 0 && $discountPerUnit >= $unitPrice) {
            throw ValidationException::withMessages([
                'cart' => \App\Support\UiText::translate('The discount per unit must be less than the unit price.'),
            ]);
        }
    }

    if (! InventorySettings::warehouseEnabled()) {
        $this->stock_location_id = (string) $inventory->getDispensingLocation((int) $this->branch_id)->id;
    }

    if (blank($this->stock_location_id)) {
        throw ValidationException::withMessages(['stock_location_id' => \App\Support\UiText::translate('Select Selling Location')]);
    }

    $location = StockLocation::findOrFail($this->stock_location_id);
    if (! InventorySettings::canUserSellFromLocation(auth()->user(), $location)) {
        throw ValidationException::withMessages(['stock_location_id' => 'Huna ruhusa ya kuuza kutoka sehemu hii ya stock.']);
    }

    if (collect($this->payments)->contains(fn ($payment) => $payment['payment_method'] === 'credit') && ! $this->canCreditSale()) {
        throw ValidationException::withMessages(['payments' => \App\Support\UiText::translate('You are not authorized to create credit sales.')]);
    }

    if ($this->usesCreditPayment() && blank($this->customer_id) && ! $this->unassigned_credit_confirmed) {
        if (! (bool) (InventorySettings::current()->allow_credit_sale_without_customer ?? true) || ! $this->canCreateUnassignedCreditSale()) {
            throw ValidationException::withMessages(['customer_id' => \App\Support\UiText::translate('Select a customer or create a customer before completing this credit sale.')]);
        }

        $this->dispatch('open-modal', 'unassigned-credit-warning');

        return;
    }

    $sale = $inventory->completeSale(
        $this->cart,
        $this->payments,
        $this->customer_id ? (int) $this->customer_id : null,
        (int) $this->stock_location_id,
        (int) $this->branch_id,
        auth()->id(),
        $this->notes,
        false,
        [
            'temporary_customer_name' => $this->temporary_customer_name ?: null,
            'temporary_customer_phone' => $this->temporary_customer_phone ?: null,
            'project_name' => $this->project_name ?: null,
            'vehicle_number' => $this->vehicle_number ?: null,
            'expected_payment_date' => $this->expected_payment_date ?: null,
            'credit_notes' => $this->credit_notes ?: null,
        ],
    );

    session()->flash('success', \App\Support\UiText::translate('Sale completed successfully.'));
    $this->redirectRoute('sales.receipt', $sale, navigate: true);
};

?>

<div>
    @php
        $t = fn ($value) => \App\Support\UiText::translate($value);
        $saleTypeBadge = $sale_type === 'wholesale'
            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
            : 'bg-blue-100 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300';
        $allowedLocations = $this->allowedSaleLocations();
        $selectedSaleLocation = $this->currentSaleLocation();
        $locationReady = $this->hasSelectedSaleLocation();
        $selectedCustomer = $customer_id ? Customer::query()->find($customer_id) : null;
        $remainingCredit = max(0, $this->grandTotal() - $this->paidTotal());
    @endphp

    <x-page-header title="POS Sales" description="Select the selling location before loading available stock." :breadcrumbs="['Dashboard' => route('dashboard'), 'POS Sales' => null]" />

    <div class="grid gap-6 xl:grid-cols-[1fr_440px]">
        <div class="space-y-5">
            <x-card>
                <div class="grid gap-3 md:grid-cols-3">
                    <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-700 dark:bg-white/5" placeholder="{{ $t('Search products...') }}" @disabled(! $locationReady)>
                    <input wire:model="barcode" wire:keydown.enter="addBarcode" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-700 dark:bg-white/5" placeholder="{{ $t('Barcode input') }}" @disabled(! $locationReady)>
                    @if (InventorySettings::warehouseEnabled() && $allowedLocations->count() > 1)
                        <label class="block text-xs font-black uppercase tracking-wide text-slate-500 dark:text-slate-400" for="selling-location">
                            {{ $t('Selling From') }} *
                            <select id="selling-location" wire:change="requestStockLocationChange($event.target.value)" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-normal normal-case tracking-normal text-slate-900 dark:border-slate-700 dark:bg-navy-950 dark:text-white" aria-label="{{ $t('Selling From') }}">
                                <option value="" @selected(blank($stock_location_id))>{{ $t('Select Selling Location') }}</option>
                                @foreach ($allowedLocations as $location)
                                    <option value="{{ $location->id }}" @selected((string) $stock_location_id === (string) $location->id)>{{ InventorySettings::stockLocationLabel($location) }}</option>
                                @endforeach
                            </select>
                        </label>
                    @else
                        <div class="rounded-lg border border-cyan-200 bg-cyan-50 px-3 py-3 text-sm font-bold text-cyan-800 dark:border-cyan-500/30 dark:bg-cyan-500/10 dark:text-cyan-100">
                            Unauza kutoka: {{ $selectedSaleLocation ? InventorySettings::stockLocationLabel($selectedSaleLocation) : $t('Selling from Dispensing Area') }}
                        </div>
                    @endif
                </div>
                @error('stock_location_id') <p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
            </x-card>

            <x-card>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm font-black text-slate-700 dark:text-slate-200">Aina ya Bei</p>
                        <span class="mt-1 inline-flex rounded-full px-3 py-1 text-xs font-black {{ $saleTypeBadge }}">{{ str($sale_type)->title() }}</span>
                    </div>
                    <div class="inline-flex rounded-lg border border-slate-200 bg-white p-1 dark:border-slate-700 dark:bg-navy-950">
                        <label class="cursor-pointer rounded-md px-3 py-2 text-sm font-black {{ $sale_type === 'retail' ? 'bg-blue-500 text-white' : 'text-slate-600 dark:text-slate-300' }}">
                            <input type="radio" wire:model.live="sale_type" value="retail" class="sr-only">
                            Retail
                        </label>
                        <label class="cursor-pointer rounded-md px-3 py-2 text-sm font-black {{ $sale_type === 'wholesale' ? 'bg-emerald-500 text-white' : 'text-slate-600 dark:text-slate-300' }}">
                            <input type="radio" wire:model.live="sale_type" value="wholesale" class="sr-only">
                            Wholesale
                        </label>
                    </div>
                </div>
                @error('sale_type') <p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
            </x-card>

            @php
                $products = $locationReady
                    ? Product::with(['category', 'measurementType', 'unit', 'sellingUnit', 'size'])
                        ->where('status', 'active')
                        ->when($search, fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")->orWhere('barcode', 'like', "%{$search}%")->orWhereHas('size', fn ($size) => $size->where('name', 'like', "%{$search}%")->orWhere('symbol', 'like', "%{$search}%"))))
                        ->orderBy('name')
                        ->take(24)
                        ->get()
                    : collect();
                $stockProductIds = $products->pluck('id')
                    ->merge(collect($cart)->pluck('product_id'))
                    ->filter()
                    ->unique()
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all();
                $stockByProduct = $locationReady
                    ? app(InventoryService::class)->getProductStocks(
                        $stockProductIds,
                        (int) $selectedSaleLocation->id,
                        (int) $branch_id,
                    )
                    : [];
                $availableStock = fn (int $productId, float $conversionFactor = 1): float =>
                    ($stockByProduct[$productId] ?? 0) * $conversionFactor;
                $stockLabel = InventorySettings::warehouseEnabled() ? $t('Stock') : $t('Available Stock');
            @endphp
            @unless ($locationReady)
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                    {{ $t('Select Selling Location') }}
                </div>
            @endunless
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($products as $product)
                    @php
                        $displayPrice = $sale_type === 'wholesale' ? $product->wholesale_price : $product->selling_price;
                        $supportsFractionalSales = $product->allowsDecimalQuantities();
                        $sellingUnitLabel = $product->sellingUnit?->short_name ?: $product->unit?->short_name;
                        $baseUnitLabel = $product->unit?->short_name;
                        $conversionFactor = $product->saleConversionFactor();
                        $available = $availableStock($product->id, $conversionFactor);
                        $baseAvailable = $available / $conversionFactor;
                        $showsBaseStock = $product->usesUnitConversion() && $baseUnitLabel && ($sellingUnitLabel !== $baseUnitLabel || abs($conversionFactor - 1) > 0.0001);
                    @endphp
                    <button type="button" wire:click="addProduct({{ $product->id }})" class="rounded-xl border border-slate-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-soft disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-800 dark:bg-navy-900" @disabled(! $locationReady)>
                        <img class="h-24 w-full rounded-lg object-cover" src="{{ $product->image ? asset('storage/'.$product->image) : 'https://ui-avatars.com/api/?name='.urlencode($product->name).'&background=f97316&color=fff' }}" alt="{{ $product->name }}">
                        <p class="mt-3 font-black">{{ $product->displayName() }}</p>
                        @if ($product->sizeLabel())
                            <p class="text-xs font-bold text-cyan-700 dark:text-cyan-200">{{ $t('Size') }}: {{ $product->sizeLabel() }}</p>
                        @endif
                        <p class="text-xs text-slate-500">{{ $product->sku }} / {{ $sellingUnitLabel }}</p>
                        <p class="text-[11px] font-bold text-slate-500">{{ $product->measurementType?->name ?? str($product->measurementCode())->title() }}</p>
                        <div class="mt-2 flex items-start justify-between gap-3 text-sm">
                            <span class="font-bold text-build-orange">TZS {{ \App\Support\NumberFormatter::money($displayPrice) }}</span>
                            <span class="text-right text-slate-500">
                                <span class="block">{{ $stockLabel }}: {{ \App\Support\NumberFormatter::quantity($available) }} {{ $sellingUnitLabel }}</span>
                                @if ($showsBaseStock)
                                    <span class="block text-[11px]">{{ $t('Base Stock') }}: {{ \App\Support\NumberFormatter::quantity($baseAvailable) }} {{ $baseUnitLabel }}</span>
                                @endif
                            </span>
                        </div>
                        @if ($showsBaseStock)
                            <p class="mt-2 rounded-lg bg-cyan-50 px-2 py-1 text-[11px] font-bold text-cyan-700 dark:bg-cyan-500/10 dark:text-cyan-200">
                                1 {{ $baseUnitLabel }} = {{ \App\Support\NumberFormatter::quantity($conversionFactor) }} {{ $sellingUnitLabel }}
                            </p>
                        @endif
                        @if ($supportsFractionalSales)
                            <span class="mt-2 inline-flex rounded-full bg-cyan-100 px-2 py-0.5 text-[11px] font-black text-cyan-700 dark:bg-cyan-500/10 dark:text-cyan-200">{{ $t('Fractional Sale') }}</span>
                        @endif
                    </button>
                @endforeach
            </div>
        </div>

        <x-card title="Cart & Payment" class="xl:sticky xl:top-24 xl:max-h-[calc(100vh-7rem)] xl:overflow-y-auto">
            <div class="space-y-3">
                <div class="flex gap-2">
                    <select wire:model="customer_id" class="min-w-0 flex-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        <option value="">{{ $t('Walk-in Customer') }}</option>
                        @foreach (Customer::where('status', 'active')->where(fn ($query) => $query->where('is_system_customer', false)->orWhereNull('is_system_customer'))->orderBy('name')->get() as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }} / {{ $customer->customer_type }}</option>
                        @endforeach
                    </select>
                    <button type="button" wire:click="openQuickCustomerModal" class="grid h-10 w-10 shrink-0 place-items-center rounded-lg border border-cyan-200 bg-cyan-50 text-xl font-black leading-none text-cyan-700 transition hover:border-cyan-400 hover:bg-cyan-100 dark:border-cyan-500/30 dark:bg-cyan-500/10 dark:text-cyan-200" title="{{ $t('Create Customer') }}" aria-label="{{ $t('Create Customer') }}">
                        +
                    </button>
                </div>
                @error('customer_id') <p class="text-sm font-semibold text-red-600">{{ $message }}</p> @enderror

                @if ($this->usesCreditPayment())
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                        @if ($selectedCustomer)
                            <div class="flex justify-between gap-3"><span>{{ $t('Customer') }}</span><span class="font-black text-right">{{ $selectedCustomer->name }}</span></div>
                            <div class="mt-1 flex justify-between gap-3"><span>{{ $t('Current Outstanding Balance') }}</span><span class="font-black">TZS {{ \App\Support\NumberFormatter::money($selectedCustomer->balance_amount) }}</span></div>
                        @else
                            <span class="inline-flex rounded-full bg-amber-200 px-2.5 py-1 text-xs font-black text-amber-900 dark:bg-amber-400/20 dark:text-amber-100">{{ $t('Unassigned Credit Sale') }}</span>
                        @endif
                        <div class="mt-2 grid gap-1">
                            <div class="flex justify-between gap-3"><span>{{ $t('Sale Total') }}</span><span class="font-black">TZS {{ \App\Support\NumberFormatter::money($this->grandTotal()) }}</span></div>
                            <div class="flex justify-between gap-3"><span>{{ $t('Amount Paid Now') }}</span><span class="font-black">TZS {{ \App\Support\NumberFormatter::money($this->paidTotal()) }}</span></div>
                            <div class="flex justify-between gap-3"><span>{{ $t('Remaining Credit') }}</span><span class="font-black">TZS {{ \App\Support\NumberFormatter::money($remainingCredit) }}</span></div>
                        </div>
                    </div>
                @endif

                @foreach ($cart as $index => $item)
                    @php
                        $quantity = (float) ($item['quantity'] ?? 0);
                        $unitPrice = (float) ($item['unit_price'] ?? 0);
                        $discountPerUnit = (float) ($item['discount_amount'] ?? 0);
                        $taxPerUnit = (float) ($item['tax_amount'] ?? 0);
                        $sellingUnitLabel = $item['selling_unit'] ?? '';
                        $baseUnitLabel = $item['base_unit'] ?? '';
                        $isFractionalSale = (bool) ($item['allow_fractional_sale'] ?? false);
                        $conversionFactor = max(0.0001, (float) ($item['conversion_factor'] ?? 1));
                        $baseQuantity = $quantity / $conversionFactor;
                        $availableSellingQuantity = $availableStock((int) ($item['product_id'] ?? 0), $conversionFactor);
                        $availableBaseQuantity = $availableSellingQuantity / $conversionFactor;
                        $quantityStep = (string) ($item['quantity_step'] ?? 1);
                        $minimumQuantity = (string) ($item['minimum_sale_quantity'] ?? 1);
                    @endphp
                    <div class="rounded-lg bg-slate-50 p-3 dark:bg-white/5">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-bold">{{ $item['name'] }}</p>
                                @if (! empty($item['size']))
                                    <p class="text-xs font-bold text-cyan-700 dark:text-cyan-200">{{ $t('Size') }}: {{ $item['size'] }}</p>
                                @endif
                                <p class="text-xs text-slate-500">{{ $item['sku'] }} / {{ $sellingUnitLabel }}</p>
                                <p class="text-[11px] font-bold text-slate-500">{{ $item['measurement_type'] ?? 'Count' }}</p>
                                <div class="mt-1 flex flex-wrap gap-1.5">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-black {{ ($item['sale_type'] ?? 'retail') === 'wholesale' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-blue-100 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' }}">{{ str($item['sale_type'] ?? 'retail')->title() }}</span>
                                    @if ($isFractionalSale)
                                        <span class="inline-flex rounded-full bg-cyan-100 px-2 py-0.5 text-[11px] font-black text-cyan-700 dark:bg-cyan-500/10 dark:text-cyan-200">{{ $t('Fractional') }}</span>
                                    @endif
                                </div>
                            </div>
                            <button wire:click="removeItem({{ $index }})" class="text-xs font-bold text-red-600">{{ $t('Remove') }}</button>
                        </div>
                        <div class="mt-3 grid gap-2 sm:grid-cols-4">
                            <label class="block min-w-0 text-[11px] font-black uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                {{ $t('Qty') }} ({{ $sellingUnitLabel ?: '-' }})
                                <input wire:model.live.debounce.75ms="cart.{{ $index }}.quantity" type="number" inputmode="decimal" step="{{ $isFractionalSale ? '0.0001' : '1' }}" min="{{ $minimumQuantity }}" class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-1 text-sm font-semibold normal-case tracking-normal text-slate-900 dark:border-slate-700 dark:bg-navy-950 dark:text-white">
                            </label>
                            <label class="block min-w-0 text-[11px] font-black uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                {{ $t('Unit Price') }}
                                <span data-money-field wire:ignore wire:key="pos-unit-price-{{ $item['product_id'] }}" class="mt-1 block min-w-0">
                                    <input type="text" inputmode="decimal" data-money-display class="w-full rounded-lg border border-slate-200 px-2 py-1 text-sm normal-case tracking-normal dark:border-slate-700 dark:bg-navy-950">
                                    <input type="hidden" data-money-value value="{{ $item['unit_price'] ?? '' }}" wire:model.live="cart.{{ $index }}.unit_price">
                                </span>
                            </label>
                            <label class="block min-w-0 text-[11px] font-black uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                {{ $t('Discount') }}
                                <span data-money-field wire:ignore class="mt-1 block min-w-0">
                                    <input type="text" inputmode="decimal" data-money-display class="w-full rounded-lg border border-slate-200 px-2 py-1 text-sm normal-case tracking-normal dark:border-slate-700 dark:bg-navy-950">
                                    <input type="hidden" data-money-value value="{{ $item['discount_amount'] ?? '' }}" wire:model.live="cart.{{ $index }}.discount_amount">
                                </span>
                            </label>
                            <label class="block min-w-0 text-[11px] font-black uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                {{ $t('VAT') }}
                                <span data-money-field wire:ignore class="mt-1 block min-w-0">
                                    <input type="text" inputmode="decimal" data-money-display class="w-full rounded-lg border border-slate-200 px-2 py-1 text-sm normal-case tracking-normal dark:border-slate-700 dark:bg-navy-950">
                                    <input type="hidden" data-money-value value="{{ $item['tax_amount'] ?? '' }}" wire:model.live="cart.{{ $index }}.tax_amount">
                                </span>
                            </label>
                        </div>

                        <div class="mt-2 grid gap-1 text-xs text-slate-500 dark:text-slate-400">
                            @if ($isFractionalSale || abs($conversionFactor - 1) > 0.0001)
                                <div class="flex justify-between"><span>{{ $t('Available Stock') }}</span><span>{{ \App\Support\NumberFormatter::quantity($availableSellingQuantity) }} {{ $sellingUnitLabel }}</span></div>
                                @if ($baseUnitLabel && ($baseUnitLabel !== $sellingUnitLabel || abs($conversionFactor - 1) > 0.0001))
                                    <div class="flex justify-between"><span>{{ $t('Available Base Stock') }}</span><span>{{ \App\Support\NumberFormatter::quantity($availableBaseQuantity) }} {{ $baseUnitLabel }}</span></div>
                                    <div class="flex justify-between"><span>{{ $t('Unit Conversion') }}</span><span>1 {{ $baseUnitLabel }} = {{ \App\Support\NumberFormatter::quantity($conversionFactor) }} {{ $sellingUnitLabel }}</span></div>
                                @endif
                                <div class="flex justify-between font-black text-slate-700 dark:text-slate-200"><span>{{ $t('Selling Quantity') }}</span><span>{{ \App\Support\NumberFormatter::quantity($quantity) }} {{ $sellingUnitLabel }}</span></div>
                                <div class="flex justify-between font-black text-slate-700 dark:text-slate-200"><span>{{ $t('Base Quantity Deducted') }}</span><span>{{ \App\Support\NumberFormatter::quantity($baseQuantity) }} {{ $baseUnitLabel }}</span></div>
                            @endif
                        </div>
                    </div>
                @endforeach

                @error('cart') <p class="text-sm font-semibold text-red-600">{{ $message }}</p> @enderror

                <div class="space-y-2 border-t border-slate-200 pt-3 text-sm dark:border-slate-800">
                    <div class="flex justify-between"><span>{{ $t('Subtotal') }}</span><span>TZS {{ \App\Support\NumberFormatter::money($this->subtotal()) }}</span></div>
                    <div class="flex justify-between"><span>{{ $t('Discount') }}</span><span>TZS {{ \App\Support\NumberFormatter::money($this->discountTotal()) }}</span></div>
                    <div class="flex justify-between"><span>{{ $t('Tax/VAT') }}</span><span>TZS {{ \App\Support\NumberFormatter::money($this->taxTotal()) }}</span></div>
                    <div class="flex justify-between text-lg font-black"><span>{{ $t('Grand Total') }}</span><span>TZS {{ \App\Support\NumberFormatter::money($this->grandTotal()) }}</span></div>
                    <div class="flex justify-between"><span>{{ $t('Paid') }}</span><span>TZS {{ \App\Support\NumberFormatter::money($this->paidTotal()) }}</span></div>
                    @if ($this->paidTotal() >= $this->grandTotal())
                        <div class="flex justify-between"><span>{{ $t('Change') }}</span><span>TZS {{ \App\Support\NumberFormatter::money($this->paidTotal() - $this->grandTotal()) }}</span></div>
                    @else
                        <div class="flex justify-between"><span>{{ $t('Balance') }}</span><span>TZS {{ \App\Support\NumberFormatter::money($this->grandTotal() - $this->paidTotal()) }}</span></div>
                    @endif
                </div>

                <div class="space-y-2">
                    @foreach ($payments as $index => $payment)
                        <div class="grid grid-cols-[1fr_1fr_auto] gap-2">
                            <select wire:model.live="payments.{{ $index }}.payment_method" wire:change="syncDefaultPaymentAmount" class="rounded-lg border border-slate-200 bg-white px-2 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                                <option value="cash">{{ $t('Cash') }}</option>
                                <option value="mobile_money">{{ $t('Mobile Money') }}</option>
                                <option value="bank">{{ $t('Bank') }}</option>
                                @if ($this->canCreditSale())
                                    <option value="credit">{{ $t('Credit') }}</option>
                                @endif
                            </select>
                            <span data-money-field wire:ignore class="block min-w-0">
                                <input type="text" inputmode="decimal" data-money-display class="w-full rounded-lg border border-slate-200 px-2 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                                <input type="hidden" data-money-value data-money-manual-flag="payment_amount_manually_edited" value="{{ $payment['amount'] ?? '' }}" wire:model.live="payments.{{ $index }}.amount">
                            </span>
                            <button wire:click="removePayment({{ $index }})" type="button" class="rounded-lg border border-slate-200 px-2 text-xs font-bold dark:border-slate-700">X</button>
                        </div>
                    @endforeach
                    @error('payments') <p class="text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
                    @error('payments.*.amount') <p class="text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
                    <button type="button" wire:click="addPayment" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700">{{ $t('Add Payment') }}</button>
                </div>

                <button wire:click="completeSale" class="w-full rounded-xl bg-build-orange px-4 py-3 font-black text-white shadow-lg shadow-orange-500/20">{{ $t('Complete Sale') }}</button>
            </div>
        </x-card>
    </div>

    <div class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 p-3 shadow-2xl backdrop-blur dark:border-slate-800 dark:bg-slate-950/95 xl:hidden">
        <button wire:click="completeSale" class="flex w-full items-center justify-between rounded-xl bg-build-orange px-4 py-3 font-black text-white">
            <span>{{ $t('Checkout') }}</span>
            <span>TZS {{ \App\Support\NumberFormatter::money($this->grandTotal()) }}</span>
        </button>
    </div>

    <x-modal name="confirm-selling-location-change" maxWidth="md">
        <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
            <h2 class="text-lg font-black text-slate-900 dark:text-white">{{ $t('Change Selling Location') }}</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $t('Changing the selling location will clear the current cart.') }}</p>
        </div>
        <div class="px-5 py-5">
            <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $t('Do you want to continue?') }}</p>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" wire:click="cancelStockLocationChange" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ $t('Cancel') }}</button>
                <button type="button" wire:click="confirmStockLocationChange" class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white shadow-lg shadow-orange-500/25">{{ $t('Continue') }}</button>
            </div>
        </div>
    </x-modal>

    <x-modal name="unassigned-credit-warning" maxWidth="2xl">
        <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
            <h2 class="text-lg font-black text-slate-900 dark:text-white">{{ $t('Customer Not Selected') }}</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $t('This sale will be recorded as unassigned credit. It may be assigned to the correct customer later.') }}</p>
        </div>
        <div class="px-5 py-5">
            <div class="grid gap-3 sm:grid-cols-2">
                <x-form-input label="Customer Name" name="temporary_customer_name" wire:model="temporary_customer_name" />
                <x-form-input label="Phone Number" name="temporary_customer_phone" wire:model="temporary_customer_phone" />
                <x-form-input label="Site or Project Name" name="project_name" wire:model="project_name" />
                <x-form-input label="Vehicle Number" name="vehicle_number" wire:model="vehicle_number" />
                <x-form-input label="Expected Payment Date" name="expected_payment_date" type="date" wire:model="expected_payment_date" />
                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200 sm:col-span-2">
                    {{ $t('Notes') }}
                    <textarea wire:model="credit_notes" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
                    @error('credit_notes') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
            </div>
            <div class="mt-5 flex flex-wrap justify-end gap-2">
                <button type="button" wire:click="selectCustomerFromWarning" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ $t('Select Customer') }}</button>
                <button type="button" wire:click="quickAddCustomerFromWarning" class="rounded-xl border border-cyan-200 bg-cyan-50 px-4 py-2.5 text-sm font-black text-cyan-700 dark:border-cyan-500/30 dark:bg-cyan-500/10 dark:text-cyan-100">{{ $t('Quick Add Customer') }}</button>
                <button type="button" wire:click="continueWithoutCustomer" class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white shadow-lg shadow-orange-500/25">{{ $t('Continue Without Customer') }}</button>
                <button type="button" wire:click="cancelUnassignedCreditWarning" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ $t('Cancel') }}</button>
            </div>
        </div>
    </x-modal>

    <x-modal name="quick-customer" maxWidth="3xl">
        <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
            <div>
                <h2 class="text-lg font-black text-slate-900 dark:text-white">{{ $t('Create Customer') }}</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $t('Add a credit customer without leaving POS.') }}</p>
            </div>
            <button type="button" x-on:click="$dispatch('close-modal', 'quick-customer')" class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-black dark:border-slate-700">X</button>
        </div>

        <form wire:submit="saveQuickCustomer" class="max-h-[calc(100vh-9rem)] overflow-y-auto px-5 py-5">
            <div class="grid gap-4 md:grid-cols-2">
                <x-form-input label="Customer Name" name="quick_customer_name" wire:model="quick_customer_name" required />
                <x-form-input label="Phone" name="quick_customer_phone" wire:model="quick_customer_phone" />
                <x-form-input label="Email" name="quick_customer_email" type="email" wire:model="quick_customer_email" />

                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                    {{ $t('Customer Type') }}
                    <select wire:model="quick_customer_type" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        <option value="cash">{{ $t('Cash') }}</option>
                        <option value="credit">{{ $t('Credit') }} / {{ $t('Mkopo') }}</option>
                        <option value="contractor">{{ $t('Contractor') }}</option>
                        <option value="wholesale">{{ $t('Wholesale') }}</option>
                    </select>
                    @error('quick_customer_type') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>

                <x-tanzania-location-selects
                    :region="$quick_customer_region"
                    :district="$quick_customer_district"
                    region-model="quick_customer_region"
                    district-model="quick_customer_district"
                    region-name="quick_customer_region"
                    district-name="quick_customer_district"
                />

                <x-money-input label="Opening Balance" name="quick_customer_opening_balance" wire:model="quick_customer_opening_balance" required />

                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                    {{ $t('Branch') }}
                    <select wire:model="quick_customer_branch_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        <option value="">{{ $t('Global customer') }}</option>
                        @foreach (Branch::orderBy('name')->get() as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                    @error('quick_customer_branch_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                    {{ $t('Status') }}
                    <select wire:model="quick_customer_status" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        <option value="active">{{ $t('Active') }}</option>
                        <option value="inactive">{{ $t('Inactive') }}</option>
                    </select>
                    @error('quick_customer_status') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200 md:col-span-2">
                    {{ $t('Address') }}
                    <textarea wire:model="quick_customer_address" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
                    @error('quick_customer_address') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
            </div>

            <div class="sticky bottom-0 -mx-5 mt-5 flex justify-end gap-2 border-t border-slate-200 bg-white px-5 py-4 dark:border-slate-800 dark:bg-slate-900">
                <button type="button" x-on:click="$dispatch('close-modal', 'quick-customer')" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ $t('Cancel') }}</button>
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white shadow-lg shadow-orange-500/25">{{ $t('Save Customer') }}</button>
            </div>
        </form>
    </x-modal>
</div>
