<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockLocation;
use App\Services\InventoryService;
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
]);

mount(function (InventoryService $inventory) {
    $this->branch_id = (string) (auth()->user()->branch_id ?: Branch::where('code', 'MAIN')->value('id'));
    $this->stock_location_id = (string) $inventory->getDispensingLocation((int) $this->branch_id)->id;
});

$canSellFromStore = fn () => auth()->user()->hasAnyRole(['Super Admin', 'Admin', 'Manager', 'Store Keeper']);
$canCreditSale = fn () => auth()->user()->hasAnyRole(['Super Admin', 'Admin', 'Manager']);

$availableQuantity = function (int $productId) {
    return app(InventoryService::class)->getProductStock($productId, (int) $this->stock_location_id, (int) $this->branch_id);
};

$addProduct = function (int $productId) {
    $product = Product::findOrFail($productId);
    $available = $this->availableQuantity($productId);

    if ($available <= 0) {
        $this->addError('cart', 'Product is out of stock in selected source.');
        return;
    }

    foreach ($this->cart as $index => $item) {
        if ((int) $item['product_id'] === $productId) {
            $this->cart[$index]['quantity'] = (string) min($available, (float) $item['quantity'] + 1);
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
};

$addPayment = function () {
    $this->payments[] = ['payment_method' => 'cash', 'amount' => '0', 'reference_number' => ''];
};

$removePayment = function (int $index) {
    unset($this->payments[$index]);
    $this->payments = array_values($this->payments);
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

    $location = StockLocation::findOrFail($this->stock_location_id);
    if ($location->type === 'store' && ! $this->canSellFromStore()) {
        throw ValidationException::withMessages(['stock_location_id' => 'You are not authorized to sell from Main Store.']);
    }

    if (collect($this->payments)->contains(fn ($payment) => $payment['payment_method'] === 'credit') && ! $this->canCreditSale()) {
        throw ValidationException::withMessages(['payments' => 'You are not authorized to create credit sales.']);
    }

    $sale = $inventory->completeSale($this->cart, $this->payments, $this->customer_id ? (int) $this->customer_id : null, (int) $this->stock_location_id, (int) $this->branch_id, auth()->id(), $this->notes);

    session()->flash('success', 'Sale completed successfully.');
    $this->redirectRoute('sales.receipt', $sale, navigate: true);
};

?>

<div>
    <x-page-header title="POS Sales" description="Sell from Dispensing Area by default, or Main Store when authorized." :breadcrumbs="['Dashboard' => route('dashboard'), 'POS Sales' => null]" />

    <div class="grid gap-6 xl:grid-cols-[1fr_440px]">
        <div class="space-y-5">
            <x-card>
                <div class="grid gap-3 md:grid-cols-3">
                    <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="Search products...">
                    <input wire:model="barcode" wire:keydown.enter="addBarcode" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="Barcode input">
                    <select wire:model.live="stock_location_id" class="rounded-lg border border-slate-200 bg-white px-3 py-3 text-sm dark:border-slate-700 dark:bg-navy-950">
                        @foreach (StockLocation::where('status', 'active')->whereIn('type', $this->canSellFromStore() ? ['store', 'dispensing'] : ['dispensing'])->orderBy('type')->get() as $location)
                            <option value="{{ $location->id }}">{{ $location->name }}</option>
                        @endforeach
                    </select>
                </div>
            </x-card>

            @php
                $products = Product::with(['unit'])
                    ->where('status', 'active')
                    ->when($search, fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")->orWhere('barcode', 'like', "%{$search}%")))
                    ->orderBy('name')
                    ->take(24)
                    ->get();
            @endphp
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($products as $product)
                    @php $available = $this->availableQuantity($product->id); @endphp
                    <button type="button" wire:click="addProduct({{ $product->id }})" class="rounded-xl border border-slate-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-soft dark:border-slate-800 dark:bg-navy-900">
                        <img class="h-24 w-full rounded-lg object-cover" src="{{ $product->image ? asset('storage/'.$product->image) : 'https://ui-avatars.com/api/?name='.urlencode($product->name).'&background=f97316&color=fff' }}" alt="{{ $product->name }}">
                        <p class="mt-3 font-black">{{ $product->name }}</p>
                        <p class="text-xs text-slate-500">{{ $product->sku }} / {{ $product->unit?->short_name }}</p>
                        <div class="mt-2 flex items-center justify-between text-sm"><span class="font-bold text-build-orange">TZS {{ number_format((float) $product->selling_price, 2) }}</span><span class="text-slate-500">Stock {{ number_format($available, 2) }}</span></div>
                    </button>
                @endforeach
            </div>
        </div>

        <x-card title="Cart & Payment" class="xl:sticky xl:top-24 xl:max-h-[calc(100vh-7rem)] xl:overflow-y-auto">
            <div class="space-y-3">
                <select wire:model="customer_id" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">Walk-in Customer</option>
                    @foreach (Customer::where('status', 'active')->orderBy('name')->get() as $customer)
                        <option value="{{ $customer->id }}">{{ $customer->name }} / {{ $customer->customer_type }}</option>
                    @endforeach
                </select>

                @foreach ($cart as $index => $item)
                    <div class="rounded-lg bg-slate-50 p-3 dark:bg-white/5">
                        <div class="flex items-start justify-between gap-3">
                            <div><p class="font-bold">{{ $item['name'] }}</p><p class="text-xs text-slate-500">{{ $item['sku'] }}</p></div>
                            <button wire:click="removeItem({{ $index }})" class="text-xs font-bold text-red-600">Remove</button>
                        </div>
                        <div class="mt-3 grid grid-cols-4 gap-2">
                            <input wire:model.live="cart.{{ $index }}.quantity" type="number" step="0.01" class="rounded-lg border border-slate-200 px-2 py-1 text-sm dark:border-slate-700 dark:bg-navy-950">
                            <span data-money-field class="block min-w-0">
                                <input type="text" inputmode="decimal" data-money-display class="w-full rounded-lg border border-slate-200 px-2 py-1 text-sm dark:border-slate-700 dark:bg-navy-950">
                                <input type="hidden" data-money-value wire:model.live="cart.{{ $index }}.unit_price">
                            </span>
                            <span data-money-field class="block min-w-0">
                                <input type="text" inputmode="decimal" data-money-display class="w-full rounded-lg border border-slate-200 px-2 py-1 text-sm dark:border-slate-700 dark:bg-navy-950">
                                <input type="hidden" data-money-value wire:model.live="cart.{{ $index }}.discount_amount">
                            </span>
                            <span data-money-field class="block min-w-0">
                                <input type="text" inputmode="decimal" data-money-display class="w-full rounded-lg border border-slate-200 px-2 py-1 text-sm dark:border-slate-700 dark:bg-navy-950">
                                <input type="hidden" data-money-value wire:model.live="cart.{{ $index }}.tax_amount">
                            </span>
                        </div>
                        <p class="mt-2 text-right text-sm font-black">TZS {{ number_format((float) $item['quantity'] * (float) $item['unit_price'] - (float) $item['discount_amount'] + (float) $item['tax_amount'], 2) }}</p>
                    </div>
                @endforeach

                @error('cart') <p class="text-sm font-semibold text-red-600">{{ $message }}</p> @enderror

                <div class="space-y-2 border-t border-slate-200 pt-3 text-sm dark:border-slate-800">
                    <div class="flex justify-between"><span>Subtotal</span><span>TZS {{ number_format($this->subtotal(), 2) }}</span></div>
                    <div class="flex justify-between"><span>Discount</span><span>TZS {{ number_format($this->discountTotal(), 2) }}</span></div>
                    <div class="flex justify-between"><span>Tax/VAT</span><span>TZS {{ number_format($this->taxTotal(), 2) }}</span></div>
                    <div class="flex justify-between text-lg font-black"><span>Grand Total</span><span>TZS {{ number_format($this->grandTotal(), 2) }}</span></div>
                    <div class="flex justify-between"><span>Paid</span><span>TZS {{ number_format($this->paidTotal(), 2) }}</span></div>
                    <div class="flex justify-between"><span>Balance/Change</span><span>TZS {{ number_format(abs($this->grandTotal() - $this->paidTotal()), 2) }}</span></div>
                </div>

                <div class="space-y-2">
                    @foreach ($payments as $index => $payment)
                        <div class="grid grid-cols-[1fr_1fr_auto] gap-2">
                            <select wire:model="payments.{{ $index }}.payment_method" class="rounded-lg border border-slate-200 bg-white px-2 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                                <option value="cash">Cash</option>
                                <option value="mobile_money">Mobile Money</option>
                                <option value="bank">Bank</option>
                                @if ($this->canCreditSale())
                                    <option value="credit">Credit</option>
                                @endif
                            </select>
                            <span data-money-field class="block min-w-0">
                                <input type="text" inputmode="decimal" data-money-display class="w-full rounded-lg border border-slate-200 px-2 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                                <input type="hidden" data-money-value wire:model.live="payments.{{ $index }}.amount">
                            </span>
                            <button wire:click="removePayment({{ $index }})" type="button" class="rounded-lg border border-slate-200 px-2 text-xs font-bold dark:border-slate-700">X</button>
                        </div>
                    @endforeach
                    <button type="button" wire:click="addPayment" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700">Add Payment</button>
                </div>

                <button wire:click="completeSale" class="w-full rounded-xl bg-build-orange px-4 py-3 font-black text-white shadow-lg shadow-orange-500/20">Complete Sale</button>
            </div>
        </x-card>
    </div>

    <div class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 p-3 shadow-2xl backdrop-blur dark:border-slate-800 dark:bg-slate-950/95 xl:hidden">
        <button wire:click="completeSale" class="flex w-full items-center justify-between rounded-xl bg-build-orange px-4 py-3 font-black text-white">
            <span>Checkout</span>
            <span>TZS {{ number_format($this->grandTotal(), 2) }}</span>
        </button>
    </div>
</div>
