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
    'search' => '',
    'barcode' => '',
    'customer_id' => '',
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
    'quick_customer_credit_limit' => '0',
    'quick_customer_opening_balance' => '0',
    'quick_customer_status' => 'active',
]);

mount(function (InventoryService $inventory) {
    $this->branch_id = (string) (auth()->user()->branch_id ?: Branch::where('code', 'MAIN')->value('id'));
    $this->stock_location_id = (string) $inventory->getDispensingLocation((int) $this->branch_id)->id;
    $this->quick_customer_branch_id = $this->branch_id;
});

$canSellFromStore = fn () => auth()->user()->hasAnyRole(['Super Admin', 'Admin', 'Manager', 'Store Keeper']);
$canCreditSale = fn () => auth()->user()->hasAnyRole(['Super Admin', 'Admin', 'Manager']);

$resetQuickCustomerForm = function () {
    $this->quick_customer_branch_id = $this->branch_id;
    $this->quick_customer_name = '';
    $this->quick_customer_phone = '';
    $this->quick_customer_email = '';
    $this->quick_customer_address = '';
    $this->quick_customer_region = '';
    $this->quick_customer_district = '';
    $this->quick_customer_type = 'credit';
    $this->quick_customer_credit_limit = '0';
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
        'quick_customer_phone' => ['required', 'string', 'max:30'],
        'quick_customer_email' => ['nullable', 'email', 'max:255'],
        'quick_customer_address' => ['nullable', 'string', 'max:1000'],
        'quick_customer_region' => ['nullable', 'string', 'max:255'],
        'quick_customer_district' => ['nullable', 'string', 'max:255'],
        'quick_customer_type' => ['required', 'in:cash,credit,contractor,wholesale'],
        'quick_customer_credit_limit' => ['required', 'numeric', 'min:0'],
        'quick_customer_opening_balance' => ['required', 'numeric', 'min:0'],
        'quick_customer_status' => ['required', 'in:active,inactive'],
    ]);

    $customer = Customer::create([
        'branch_id' => $data['quick_customer_branch_id'] ?: null,
        'name' => $data['quick_customer_name'],
        'phone' => $data['quick_customer_phone'],
        'email' => $data['quick_customer_email'] ?: null,
        'address' => $data['quick_customer_address'] ?: null,
        'region' => $data['quick_customer_region'] ?: null,
        'district' => $data['quick_customer_district'] ?: null,
        'customer_type' => $data['quick_customer_type'],
        'credit_limit' => $data['quick_customer_credit_limit'],
        'opening_balance' => $data['quick_customer_opening_balance'],
        'balance_amount' => $data['quick_customer_opening_balance'],
        'status' => $data['quick_customer_status'],
    ]);

    $this->customer_id = (string) $customer->id;
    $this->resetQuickCustomerForm();
    $this->dispatch('close-modal', 'quick-customer');
    session()->flash('success', \App\Support\UiText::translate('Customer created and selected.'));
};

$availableQuantity = function (int $productId) {
    $inventory = app(InventoryService::class);
    $locationId = InventorySettings::warehouseEnabled()
        ? (int) $this->stock_location_id
        : $inventory->getDispensingLocation((int) $this->branch_id)->id;

    if (! InventorySettings::warehouseEnabled()) {
        $this->stock_location_id = (string) $locationId;
    }

    return $inventory->getProductStock($productId, $locationId, (int) $this->branch_id);
};

$syncDefaultPaymentAmount = function () {
    if (! isset($this->payments[0]) || count($this->payments) !== 1) {
        return;
    }

    $this->payments[0]['amount'] = (string) $this->grandTotal();
    $this->dispatch('money-input-updated', model: 'payments.0.amount', value: $this->payments[0]['amount']);
};

$updatedPayments = function () {
    if (! isset($this->payments[0]) || count($this->payments) !== 1) {
        return;
    }

    $this->syncDefaultPaymentAmount();
};

$addProduct = function (int $productId) {
    $product = Product::findOrFail($productId);
    $available = $this->availableQuantity($productId);

    if ($available <= 0) {
        $this->addError('cart', \App\Support\UiText::translate('Product is out of stock in selected source.'));
        return;
    }

    foreach ($this->cart as $index => $item) {
        if ((int) $item['product_id'] === $productId) {
            $this->cart[$index]['quantity'] = (string) min($available, (float) $item['quantity'] + 1);
            $this->syncDefaultPaymentAmount();

            return;
        }
    }

    $this->cart[] = [
        'product_id' => $product->id,
        'name' => $product->name,
        'sku' => $product->sku,
        'quantity' => '1',
        'unit_price' => (string) $product->selling_price,
        'discount_amount' => '0',
        'tax_amount' => $product->taxable ? (string) round((float) $product->selling_price * 0.18, 2) : '0',
    ];

    $this->syncDefaultPaymentAmount();
};

$addBarcode = function () {
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

$subtotal = fn () => collect($this->cart)->sum(fn ($item) => (float) $item['quantity'] * (float) $item['unit_price']);
$discountTotal = fn () => collect($this->cart)->sum(fn ($item) => (float) ($item['discount_amount'] ?? 0));
$taxTotal = fn () => collect($this->cart)->sum(fn ($item) => (float) ($item['tax_amount'] ?? 0));
$grandTotal = fn () => max(0, $this->subtotal() - $this->discountTotal() + $this->taxTotal());
$paidTotal = fn () => collect($this->payments)->reject(fn ($payment) => $payment['payment_method'] === 'credit')->sum(fn ($payment) => (float) $payment['amount']);

$completeSale = function (InventoryService $inventory) {
    $this->validate([
        'stock_location_id' => ['required', 'exists:stock_locations,id'],
        'customer_id' => ['nullable', 'exists:customers,id'],
        'cart' => ['required', 'array', 'min:1'],
        'cart.*.product_id' => ['required', 'exists:products,id'],
        'cart.*.quantity' => ['required', 'numeric', 'gt:0'],
        'cart.*.unit_price' => ['required', 'numeric', 'min:0'],
        'cart.*.discount_amount' => ['required', 'numeric', 'min:0'],
        'cart.*.tax_amount' => ['required', 'numeric', 'min:0'],
        'payments' => ['required', 'array', 'min:1'],
        'payments.*.payment_method' => ['required', 'in:cash,mobile_money,bank,credit'],
        'payments.*.amount' => ['required', 'numeric', 'min:0'],
    ]);

    if (! InventorySettings::warehouseEnabled()) {
        $this->stock_location_id = (string) $inventory->getDispensingLocation((int) $this->branch_id)->id;
    }

    $location = StockLocation::findOrFail($this->stock_location_id);
    if ($location->type === 'store' && (! InventorySettings::salesFromStoreAllowed() || ! $this->canSellFromStore())) {
        throw ValidationException::withMessages(['stock_location_id' => \App\Support\UiText::translate('You are not authorized to sell from Main Store.')]);
    }

    if (collect($this->payments)->contains(fn ($payment) => $payment['payment_method'] === 'credit') && ! $this->canCreditSale()) {
        throw ValidationException::withMessages(['payments' => \App\Support\UiText::translate('You are not authorized to create credit sales.')]);
    }

    if (collect($this->payments)->contains(fn ($payment) => $payment['payment_method'] === 'credit') && blank($this->customer_id)) {
        throw ValidationException::withMessages(['customer_id' => \App\Support\UiText::translate('Credit sale requires a customer.')]);
    }

    $sale = $inventory->completeSale($this->cart, $this->payments, $this->customer_id ? (int) $this->customer_id : null, (int) $this->stock_location_id, (int) $this->branch_id, auth()->id(), $this->notes);

    session()->flash('success', \App\Support\UiText::translate('Sale completed successfully.'));
    $this->redirectRoute('sales.receipt', $sale, navigate: true);
};

?>

<div>
    @php
        $t = fn ($value) => \App\Support\UiText::translate($value);
    @endphp

    <x-page-header title="POS Sales" description="Sell from Dispensing Area by default, or Main Store when authorized." :breadcrumbs="['Dashboard' => route('dashboard'), 'POS Sales' => null]" />

    <div class="grid gap-6 xl:grid-cols-[1fr_440px]">
        <div class="space-y-5">
            <x-card>
                <div class="grid gap-3 md:grid-cols-3">
                    <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="{{ $t('Search products...') }}">
                    <input wire:model="barcode" wire:keydown.enter="addBarcode" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="{{ $t('Barcode input') }}">
                    @if (InventorySettings::warehouseEnabled())
                        <select wire:model.live="stock_location_id" class="rounded-lg border border-slate-200 bg-white px-3 py-3 text-sm dark:border-slate-700 dark:bg-navy-950">
                            @foreach (StockLocation::where('status', 'active')->whereIn('type', InventorySettings::salesFromStoreAllowed() && $this->canSellFromStore() ? ['store', 'dispensing'] : ['dispensing'])->orderBy('type')->get() as $location)
                                <option value="{{ $location->id }}">{{ $location->name }}</option>
                            @endforeach
                        </select>
                    @else
                        <div class="rounded-lg border border-cyan-200 bg-cyan-50 px-3 py-3 text-sm font-bold text-cyan-800 dark:border-cyan-500/30 dark:bg-cyan-500/10 dark:text-cyan-100">
                            {{ $t('Selling from Dispensing Area') }}
                        </div>
                    @endif
                </div>
            </x-card>

            @php
                $products = Product::with(['unit'])
                    ->where('status', 'active')
                    ->when($search, fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")->orWhere('barcode', 'like', "%{$search}%")))
                    ->orderBy('name')
                    ->take(24)
                    ->get();
                $stockLabel = InventorySettings::warehouseEnabled() ? $t('Stock') : $t('Available Stock');
            @endphp
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($products as $product)
                    @php $available = $this->availableQuantity($product->id); @endphp
                    <button type="button" wire:click="addProduct({{ $product->id }})" class="rounded-xl border border-slate-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-soft dark:border-slate-800 dark:bg-navy-900">
                        <img class="h-24 w-full rounded-lg object-cover" src="{{ $product->image ? asset('storage/'.$product->image) : 'https://ui-avatars.com/api/?name='.urlencode($product->name).'&background=f97316&color=fff' }}" alt="{{ $product->name }}">
                        <p class="mt-3 font-black">{{ $product->name }}</p>
                        <p class="text-xs text-slate-500">{{ $product->sku }} / {{ $product->unit?->short_name }}</p>
                        <div class="mt-2 flex items-center justify-between gap-3 text-sm"><span class="font-bold text-build-orange">TZS {{ number_format((float) $product->selling_price, 2) }}</span><span class="text-right text-slate-500">{{ $stockLabel }}: {{ number_format($available, 2) }} {{ $product->unit?->short_name }}</span></div>
                    </button>
                @endforeach
            </div>
        </div>

        <x-card title="Cart & Payment" class="xl:sticky xl:top-24 xl:max-h-[calc(100vh-7rem)] xl:overflow-y-auto">
            <div class="space-y-3">
                <div class="flex gap-2">
                    <select wire:model="customer_id" class="min-w-0 flex-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        <option value="">{{ $t('Walk-in Customer') }}</option>
                        @foreach (Customer::where('status', 'active')->orderBy('name')->get() as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }} / {{ $customer->customer_type }}</option>
                        @endforeach
                    </select>
                    <button type="button" wire:click="openQuickCustomerModal" class="grid h-10 w-10 shrink-0 place-items-center rounded-lg border border-cyan-200 bg-cyan-50 text-xl font-black leading-none text-cyan-700 transition hover:border-cyan-400 hover:bg-cyan-100 dark:border-cyan-500/30 dark:bg-cyan-500/10 dark:text-cyan-200" title="{{ $t('Create Customer') }}" aria-label="{{ $t('Create Customer') }}">
                        +
                    </button>
                </div>
                @error('customer_id') <p class="text-sm font-semibold text-red-600">{{ $message }}</p> @enderror

                @foreach ($cart as $index => $item)
                    <div class="rounded-lg bg-slate-50 p-3 dark:bg-white/5">
                        <div class="flex items-start justify-between gap-3">
                            <div><p class="font-bold">{{ $item['name'] }}</p><p class="text-xs text-slate-500">{{ $item['sku'] }}</p></div>
                            <button wire:click="removeItem({{ $index }})" class="text-xs font-bold text-red-600">{{ $t('Remove') }}</button>
                        </div>
                        <div class="mt-3 grid grid-cols-4 gap-2">
                            <input wire:model.live="cart.{{ $index }}.quantity" type="number" step="0.01" class="rounded-lg border border-slate-200 px-2 py-1 text-sm dark:border-slate-700 dark:bg-navy-950">
                            <span data-money-field wire:ignore class="block min-w-0">
                                <input type="text" inputmode="decimal" data-money-display class="w-full rounded-lg border border-slate-200 px-2 py-1 text-sm dark:border-slate-700 dark:bg-navy-950">
                                <input type="hidden" data-money-value wire:model.live="cart.{{ $index }}.unit_price">
                            </span>
                            <span data-money-field wire:ignore class="block min-w-0">
                                <input type="text" inputmode="decimal" data-money-display class="w-full rounded-lg border border-slate-200 px-2 py-1 text-sm dark:border-slate-700 dark:bg-navy-950">
                                <input type="hidden" data-money-value wire:model.live="cart.{{ $index }}.discount_amount">
                            </span>
                            <span data-money-field wire:ignore class="block min-w-0">
                                <input type="text" inputmode="decimal" data-money-display class="w-full rounded-lg border border-slate-200 px-2 py-1 text-sm dark:border-slate-700 dark:bg-navy-950">
                                <input type="hidden" data-money-value wire:model.live="cart.{{ $index }}.tax_amount">
                            </span>
                        </div>
                        <p class="mt-2 text-right text-sm font-black">TZS {{ number_format((float) $item['quantity'] * (float) $item['unit_price'] - (float) $item['discount_amount'] + (float) $item['tax_amount'], 2) }}</p>
                    </div>
                @endforeach

                @error('cart') <p class="text-sm font-semibold text-red-600">{{ $message }}</p> @enderror

                <div class="space-y-2 border-t border-slate-200 pt-3 text-sm dark:border-slate-800">
                    <div class="flex justify-between"><span>{{ $t('Subtotal') }}</span><span>TZS {{ number_format($this->subtotal(), 2) }}</span></div>
                    <div class="flex justify-between"><span>{{ $t('Discount') }}</span><span>TZS {{ number_format($this->discountTotal(), 2) }}</span></div>
                    <div class="flex justify-between"><span>{{ $t('Tax/VAT') }}</span><span>TZS {{ number_format($this->taxTotal(), 2) }}</span></div>
                    <div class="flex justify-between text-lg font-black"><span>{{ $t('Grand Total') }}</span><span>TZS {{ number_format($this->grandTotal(), 2) }}</span></div>
                    <div class="flex justify-between"><span>{{ $t('Paid') }}</span><span>TZS {{ number_format($this->paidTotal(), 2) }}</span></div>
                    <div class="flex justify-between"><span>{{ $t('Balance/Change') }}</span><span>TZS {{ number_format(abs($this->grandTotal() - $this->paidTotal()), 2) }}</span></div>
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
                                <input type="hidden" data-money-value value="{{ $payment['amount'] ?? '' }}" wire:model.live="payments.{{ $index }}.amount">
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
            <span>TZS {{ number_format($this->grandTotal(), 2) }}</span>
        </button>
    </div>

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
                <x-form-input label="Phone" name="quick_customer_phone" wire:model="quick_customer_phone" required />
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

                <x-money-input label="Credit Limit" name="quick_customer_credit_limit" wire:model="quick_customer_credit_limit" required />
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
