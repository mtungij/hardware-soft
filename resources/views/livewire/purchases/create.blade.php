<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\AccountingService;
use App\Services\InventoryService;
use App\Services\ProductUnitConversionService;
use App\Support\CompanyFeatures;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'branch_id' => '',
    'supplier_id' => '',
    'purchase_date' => '',
    'invoice_number' => '',
    'reference_number' => '',
    'notes' => '',
    'paid_amount' => 0.0,
    'payment_method' => 'cash',
    'payment_reference_number' => '',
    'subtotal' => 0.0,
    'grand_total' => 0.0,
    'balance_amount' => 0.0,
    'recalculating' => false,
    'save_status' => 'ordered',
    'send_purchase_email' => false,
    'items' => [],
]);

$toNumber = function (mixed $value): float {
    if ($value === null || trim((string) $value) === '') {
        return 0.0;
    }

    $normalized = str_replace([',', ' ', "\u{00A0}"], '', (string) $value);

    return is_numeric($normalized) ? (float) $normalized : 0.0;
};

$newItem = fn (): array => [
    'uuid' => (string) Str::uuid(),
    'product_id' => '',
    'product_unit_conversion_id' => '',
    'use_base_unit' => false,
    'purchase_unit_id' => '',
    'purchase_conversion_factor' => 1.0,
    'base_ordered_quantity' => 1.0,
    'ordered_quantity' => 1.0,
    'received_quantity' => 0.0,
    'cost_price' => 0.0,
    'selling_price' => 0.0,
    'discount' => 0.0,
    'tax' => 0.0,
    'line_total' => 0.0,
];

$normalizeNumericState = function (): void {
    foreach ($this->items as $index => $item) {
        if (blank($item['uuid'] ?? null)) {
            $this->items[$index]['uuid'] = (string) Str::uuid();
        }

        $this->items[$index]['ordered_quantity'] = $this->toNumber($item['ordered_quantity'] ?? $item['ordered_qty'] ?? 0);
        $this->items[$index]['base_ordered_quantity'] = $this->toNumber($item['ordered_quantity'] ?? 0) * max(0.0001, $this->toNumber($item['purchase_conversion_factor'] ?? 1));
        $this->items[$index]['received_quantity'] = $this->toNumber($item['received_quantity'] ?? $item['received_qty'] ?? 0);
        $this->items[$index]['cost_price'] = $this->toNumber($item['cost_price'] ?? $item['unit_cost'] ?? 0);
        $this->items[$index]['selling_price'] = $this->toNumber($item['selling_price'] ?? 0);
        $this->items[$index]['discount'] = $this->toNumber($item['discount'] ?? 0);
        $this->items[$index]['tax'] = $this->toNumber($item['tax'] ?? 0);
        $this->items[$index]['line_total'] = $this->toNumber($item['line_total'] ?? 0);
    }

    $this->paid_amount = $this->toNumber($this->paid_amount);
};

$recalculateTotals = function (): void {
    if ($this->recalculating) {
        return;
    }

    $this->recalculating = true;
    $subtotal = 0.0;
    $grandTotal = 0.0;

    foreach ($this->items as $index => $item) {
        $quantity = $this->toNumber($item['ordered_quantity'] ?? $item['ordered_qty'] ?? 0);
        $costPrice = $this->toNumber($item['cost_price'] ?? $item['unit_cost'] ?? 0);
        $discount = $this->toNumber($item['discount'] ?? 0);
        $tax = $this->toNumber($item['tax'] ?? 0);
        $grossTotal = $quantity * $costPrice;
        $lineTotal = max(0.0, $grossTotal - $discount + $tax);

        $this->items[$index]['line_total'] = $lineTotal;

        $subtotal += $grossTotal;
        $grandTotal += $lineTotal;
    }

    $paidAmount = $this->toNumber($this->paid_amount);
    $this->subtotal = $subtotal;
    $this->grand_total = $grandTotal;
    $this->balance_amount = max(0.0, $grandTotal - $paidAmount);
    $this->recalculating = false;
};

mount(function (InventoryService $inventory) {
    $this->branch_id = (string) (auth()->user()->branch_id ?: Branch::where('code', 'MAIN')->value('id'));
    $this->purchase_date = now()->toDateString();
    $this->reference_number = $inventory->generatePurchaseReference();
    $this->items = [$this->newItem()];
    $this->recalculateTotals();
});

$addItem = function () {
    if (blank($this->supplier_id)) {
        $this->addError('supplier_id', 'Select supplier before adding products.');

        return;
    }

    $this->items[] = $this->newItem();
    $this->recalculateTotals();
};

$removeItem = function (int $index) {
    unset($this->items[$index]);
    $this->items = array_values($this->items);
    $this->recalculateTotals();
};

$selectProduct = function (int $index, string $productId) {
    if (! array_key_exists($index, $this->items)) {
        return;
    }

    $product = $productId ? Product::query()->with(['unit', 'purchaseUnit', 'unitConversions.unit'])->purchasable()->find($productId) : null;
    $conversion = $product?->unitConversions->first(fn ($row) => $row->active && $row->can_purchase);
    $costPrice = $this->toNumber($conversion?->purchase_price ?? $product?->buying_price);
    $sellingPrice = $this->toNumber($product?->selling_price);

    $this->items[$index]['product_id'] = $product ? (string) $product->id : '';
    $this->items[$index]['product_unit_conversion_id'] = $conversion ? (string) $conversion->id : '';
    $this->items[$index]['use_base_unit'] = false;
    $this->items[$index]['purchase_unit_id'] = $conversion?->unit_id ?: ($product?->purchase_unit_id ?: $product?->unit_id ?: '');
    $this->items[$index]['purchase_conversion_factor'] = $conversion?->conversion_factor ?: ($product?->purchaseConversionFactor() ?? 1);
    $this->items[$index]['cost_price'] = $costPrice;
    $this->items[$index]['selling_price'] = $sellingPrice;
    $this->recalculateTotals();
    $this->dispatch('money-input-updated', model: "items.{$index}.cost_price", value: $costPrice);
    $this->dispatch('money-input-updated', model: "items.{$index}.selling_price", value: $sellingPrice);
};

$selectPurchaseUnit = function (int $index, string $selection): void {
    $item = $this->items[$index] ?? null;
    $product = $item ? Product::query()->with(['unit', 'purchaseUnit'])->find($item['product_id'] ?? null) : null;

    if (! $product) {
        return;
    }

    if ($selection === 'base') {
        $this->items[$index]['product_unit_conversion_id'] = '';
        $this->items[$index]['use_base_unit'] = true;
        $this->items[$index]['purchase_unit_id'] = $product->unit_id;
        $this->items[$index]['purchase_conversion_factor'] = 1;
        $this->items[$index]['cost_price'] = $this->toNumber($product->buying_price);
    } else {
        $conversion = app(ProductUnitConversionService::class)->resolveForPurchase($product, (int) $selection);
        $this->items[$index]['product_unit_conversion_id'] = (string) $conversion->id;
        $this->items[$index]['use_base_unit'] = false;
        $this->items[$index]['purchase_unit_id'] = $conversion->unit_id;
        $this->items[$index]['purchase_conversion_factor'] = (float) $conversion->conversion_factor;
        $this->items[$index]['cost_price'] = $this->toNumber($conversion->purchase_price ?? $product->buying_price);
    }

    $this->recalculateTotals();
};

$updatedItems = function (): void {
    $this->normalizeNumericState();
    $this->recalculateTotals();
};

$updatedPaidAmount = function (): void {
    $this->normalizeNumericState();
    $this->recalculateTotals();
};

$canUpdateSellingPrice = fn (): bool => auth()->user()?->hasAnyRole(['Super Admin', 'Admin']) ?? false;

$savePurchase = function (string $status, bool $sendEmail = false) {
    $this->normalizeNumericState();
    $this->recalculateTotals();

    $validated = $this->validate([
        'branch_id' => ['required', 'exists:branches,id'],
        'supplier_id' => ['required', 'exists:suppliers,id'],
        'purchase_date' => ['required', 'date'],
        'invoice_number' => ['nullable', 'string', 'max:255'],
        'reference_number' => ['required', 'string', 'max:255', 'unique:purchases,reference_number'],
        'notes' => ['nullable', 'string', 'max:1000'],
        'paid_amount' => ['required', 'numeric', 'min:0'],
        'payment_method' => ['required', 'in:cash,mobile_money,bank'],
        'payment_reference_number' => ['nullable', 'string', 'max:255'],
        'subtotal' => ['required', 'numeric', 'min:0'],
        'grand_total' => ['required', 'numeric', 'min:0'],
        'balance_amount' => ['required', 'numeric', 'min:0'],
        'items' => ['required', 'array', 'min:1'],
        'items.*.product_id' => [
            'required',
            Rule::exists('products', 'id')->where(function ($query): void {
                $query->where('company_id', auth()->user()->company_id);

                if (CompanyFeatures::manufacturingEnabled()) {
                    $query->where('inventory_source', Product::INVENTORY_SOURCE_PURCHASED);
                }
            }),
        ],
        'items.*.product_unit_conversion_id' => ['nullable', 'exists:product_unit_conversions,id'],
        'items.*.use_base_unit' => ['boolean'],
        'items.*.ordered_quantity' => ['required', 'numeric', 'min:0.01'],
        'items.*.received_quantity' => ['required', 'numeric', 'min:0'],
        'items.*.cost_price' => ['required', 'numeric', 'min:0'],
        'items.*.selling_price' => ['nullable', 'numeric', 'min:0'],
        'items.*.discount' => ['required', 'numeric', 'min:0'],
        'items.*.tax' => ['required', 'numeric', 'min:0'],
        'items.*.line_total' => ['required', 'numeric', 'min:0'],
    ]);

    foreach ($validated['items'] as $index => $item) {
        $product = Product::query()->with(['purchaseUnit.measurementType', 'unit'])->findOrFail($item['product_id']);
        $quantity = $this->toNumber($item['ordered_quantity']);

        if (! $product->acceptsPurchaseQuantity($quantity)) {
            throw ValidationException::withMessages([
                "items.{$index}.ordered_quantity" => $product->displayNameWithSize().' must be purchased in whole '.($product->purchaseUnit?->short_name ?: $product->unit?->short_name).' quantities.',
            ]);
        }
    }

    $total = $this->toNumber($validated['grand_total']);

    if ($this->toNumber($validated['paid_amount']) > $total) {
        throw ValidationException::withMessages(['paid_amount' => 'Paid amount cannot exceed the purchase total.']);
    }

    $canUpdateSellingPrice = $this->canUpdateSellingPrice();

    $purchase = DB::transaction(function () use ($validated, $status, $total, $canUpdateSellingPrice) {
        $paid = $this->toNumber($validated['paid_amount']);
        $balance = max(0, $total - $paid);

        $purchase = Purchase::create([
            'branch_id' => $validated['branch_id'],
            'supplier_id' => $validated['supplier_id'],
            'purchase_date' => $validated['purchase_date'],
            'invoice_number' => $validated['invoice_number'],
            'reference_number' => $validated['reference_number'],
            'status' => $status,
            'payment_status' => $balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
            'total_amount' => $total,
            'paid_amount' => $paid,
            'balance_amount' => $balance,
            'notes' => $validated['notes'],
            'created_by' => auth()->id(),
        ]);

        foreach ($validated['items'] as $item) {
            $product = Product::query()->with(['unit', 'purchaseUnit'])->findOrFail($item['product_id']);
            $conversion = app(ProductUnitConversionService::class)->resolveForPurchase(
                $product,
                filled($item['product_unit_conversion_id'] ?? null) ? (int) $item['product_unit_conversion_id'] : null,
            );
            $usesBase = (bool) ($item['use_base_unit'] ?? false);
            $purchaseUnit = $conversion?->unit ?: ($usesBase ? $product->unit : $product->purchaseUnit);
            $factor = $conversion ? (float) $conversion->conversion_factor : ($usesBase ? 1 : $product->purchaseConversionFactor());
            $quantity = $this->toNumber($item['ordered_quantity']);
            $cost = $this->toNumber($item['cost_price']);
            $sellingPriceValue = $this->toNumber($item['selling_price']);
            $lineTotal = $this->toNumber($item['line_total']);
            $sellingPrice = $canUpdateSellingPrice
                ? $sellingPriceValue
                : $this->toNumber($product->selling_price);

            $purchase->items()->create([
                'product_id' => $item['product_id'],
                'product_unit_conversion_id' => $conversion?->id,
                'purchase_unit_id' => $purchaseUnit?->id ?: $product->unit_id,
                'stock_unit_id' => $product->unit_id,
                'purchase_conversion_factor' => $factor,
                'purchase_unit_name_snapshot' => $purchaseUnit?->name,
                'purchase_unit_code_snapshot' => $purchaseUnit?->short_name,
                'stock_unit_name_snapshot' => $product->unit?->name,
                'stock_unit_code_snapshot' => $product->unit?->short_name,
                'product_size_id' => $product->product_size_id,
                'ordered_quantity' => $quantity,
                'base_ordered_quantity' => round($quantity * $factor, 4),
                'received_quantity' => 0,
                'base_received_quantity' => 0,
                'cost_price' => $cost,
                'selling_price' => $sellingPrice,
                'line_total' => $lineTotal,
            ]);

            if ($canUpdateSellingPrice) {
                $product->update(['selling_price' => $sellingPriceValue]);
            }
        }

        app(AccountingService::class)->recordInitialPurchasePayment($purchase, [
            'amount' => $paid,
            'payment_method' => $validated['payment_method'],
            'reference_number' => $validated['payment_reference_number'] ?: null,
            'payment_date' => $validated['purchase_date'],
        ], auth()->id());

        return $purchase->refresh();
    });

    if ($sendEmail) {
        try {
            app(\App\Services\PurchaseOrderEmailService::class)->send($purchase, auth()->id());
            session()->flash('success', 'Purchase saved and emailed successfully.');
        } catch (ValidationException $exception) {
            session()->flash('error', 'Purchase saved, but email was not sent: '.$exception->validator->errors()->first());
        } catch (\Throwable $exception) {
            session()->flash('error', 'Purchase saved, but email was not sent: '.$exception->getMessage());
        }
    } else {
        session()->flash('success', 'Purchase saved successfully.');
    }

    $this->redirectRoute('purchases.index', navigate: true);
};

$submitPurchase = function () {
    return $this->savePurchase($this->save_status, $this->send_purchase_email);
};

?>

<div>
    <x-page-header title="Create Purchase" description="Create a draft or ordered purchase. Stock is not increased until receiving." :breadcrumbs="['Dashboard' => route('dashboard'), 'Purchases' => route('purchases.index'), 'Create' => null]" />

    @php
        $canUpdateSellingPrice = $this->canUpdateSellingPrice();
        $stockBranchId = (int) ($branch_id ?: (auth()->user()->branch_id ?: Branch::where('code', 'MAIN')->value('id')));
        $storeLocation = StockLocation::where('branch_id', $stockBranchId)->where('type', 'store')->first();
        $inventory = app(InventoryService::class);
        $productSelectOptions = [
            'placeholder' => blank($supplier_id) ? 'Select supplier first' : 'Search or select product',
            'hasSearch' => true,
            'minSearchLength' => 0,
            'searchPlaceholder' => 'Search product by name or SKU',
            'searchNoResultText' => 'No product found',
            'optionAllowEmptyOption' => true,
            'toggleClasses' => 'relative py-2.5 ps-3 pe-9 flex w-72 cursor-pointer rounded-lg border border-slate-200 bg-white text-start text-sm text-slate-800 shadow-sm outline-none transition before:absolute before:inset-0 before:z-1 focus:border-cyan-500 focus:ring-4 focus:ring-cyan-500/20 disabled:pointer-events-none disabled:opacity-60 dark:border-slate-700 dark:bg-navy-950 dark:text-white dark:focus:border-cyan-400',
            'dropdownClasses' => 'z-[120] mt-2 max-h-80 w-96 max-w-[calc(100vw-2rem)] overflow-hidden overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-2xl dark:border-slate-700 dark:bg-slate-900',
            'optionClasses' => 'cursor-pointer rounded-lg px-3 py-2 text-sm text-slate-800 hover:bg-cyan-50 focus:bg-cyan-50 hs-selected:bg-cyan-500 hs-selected:text-white dark:text-slate-100 dark:hover:bg-cyan-500/10 dark:focus:bg-cyan-500/10 dark:hs-selected:bg-cyan-500',
            'searchClasses' => 'block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 outline-none ring-cyan-500/20 placeholder:text-slate-400 focus:border-cyan-500 focus:ring-4 dark:border-slate-700 dark:bg-slate-950 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-cyan-400',
            'searchWrapperClasses' => 'sticky top-0 z-10 bg-white p-1 dark:bg-slate-900',
            'dropdownScope' => 'parent',
            'dropdownVerticalFixedPlacement' => 'bottom',
        ];
    @endphp

    <x-card>
        <form wire:submit="submitPurchase" class="space-y-6">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">Supplier
                    <select wire:model.live="supplier_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        <option value="">Select supplier</option>
                        @foreach (Supplier::where('status', 'active')->orderBy('name')->get() as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                    @error('supplier_id') <span class="text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">Branch
                    <select wire:model="branch_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        @foreach (Branch::where('status', 'active')->orderBy('name')->get() as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </label>
                <x-form-input label="Purchase Date" name="purchase_date" type="date" wire:model="purchase_date" required />
                <x-form-input label="Invoice Number" name="invoice_number" wire:model="invoice_number" />
                <x-form-input label="Reference Number" name="reference_number" wire:model="reference_number" required />
                <x-money-input label="Paid Amount" name="paid_amount" value="{{ $paid_amount }}" wire:model.blur="paid_amount" required />
                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">Payment Method
                    <select wire:model="payment_method" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        <option value="cash">Cash</option>
                        <option value="mobile_money">Mobile Money</option>
                        <option value="bank">Bank</option>
                    </select>
                    @error('payment_method') <span class="text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                </label>
                <x-form-input label="Payment Reference" name="payment_reference_number" wire:model="payment_reference_number" />
            </div>

            <div class="relative z-20 overflow-x-auto pb-4 transition-[padding] focus-within:pb-96">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500 dark:bg-white/5"><tr><th class="px-3 py-3">Product</th><th>Purchase Unit</th><th>Ordered Qty</th><th>Conversion</th><th>Base Qty</th><th>Unit Cost</th><th>Selling Price</th><th>Line Total</th><th></th></tr></thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($items as $index => $item)
                            @php
                                $itemKey = $item['uuid'] ?? "legacy-{$index}";
                                $selectedProduct = filled($item['product_id'] ?? null)
                                    ? Product::query()->with(['size', 'purchaseUnit.measurementType', 'unit.measurementType', 'unitConversions.unit'])->find($item['product_id'])
                                    : null;
                                $selectedPurchaseUnit = filled($item['purchase_unit_id'] ?? null) ? Unit::find($item['purchase_unit_id']) : null;
                                $purchaseConversions = $selectedProduct?->unitConversions?->filter(fn ($row) => $row->active && $row->can_purchase) ?? collect();
                                $sellingPriceValue = $item['selling_price'] ?? 0;
                                $purchaseMeasurementCode = $selectedProduct?->purchaseUnit?->measurementType?->code
                                    ?? $selectedProduct?->unit?->measurementType?->code;
                                $isCountProduct = $purchaseMeasurementCode === \App\Models\MeasurementType::COUNT;
                                $quantityStep = $isCountProduct
                                    ? 1
                                    : max(0.0001, (float) ($selectedProduct?->quantity_step ?: 0.0001));
                            @endphp
                            <tr wire:key="purchase-item-{{ $itemKey }}">
                                <td class="relative z-30 px-3 py-3">
                                    <select
                                        wire:change="selectProduct({{ $index }}, $event.target.value)"
                                        wire:key="purchase-product-select-{{ $itemKey }}"
                                        data-hs-select='@json($productSelectOptions)'
                                        class="hidden"
                                        @disabled(blank($supplier_id))
                                    >
                                        <option value="">{{ blank($supplier_id) ? 'Select supplier first' : 'Select product' }}</option>
                                        @if (filled($supplier_id))
                                            @foreach (Product::with(['purchaseUnit', 'unit', 'size'])->purchasable()->where('status', 'active')->orderBy('name')->get() as $product)
                                                @php
                                                    $storeQty = $storeLocation
                                                        ? $inventory->getProductStock($product->id, $storeLocation->id, $stockBranchId)
                                                        : 0;
                                                @endphp
                                                <option value="{{ $product->id }}" @selected((string) ($item['product_id'] ?? '') === (string) $product->id)>{{ $product->displayNameWithSize() }} - Purchase: {{ $product->purchaseUnit?->short_name ?: $product->unit?->short_name }} - Store Qty: {{ \App\Support\NumberFormatter::quantity($storeQty) }} {{ $product->unit?->short_name }} / {{ $product->sku }}</option>
                                            @endforeach
                                        @endif
                                    </select>
                                    @if (blank($supplier_id))
                                        <span class="mt-1 block text-xs font-semibold text-amber-600">Select supplier before choosing products.</span>
                                    @endif
                                    @error("items.{$index}.product_id") <span class="block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                                </td>
                                <td class="px-3 py-3">
                                    @if ($purchaseConversions->isNotEmpty())
                                        <select wire:change="selectPurchaseUnit({{ $index }}, $event.target.value)" class="w-32 rounded-lg border-slate-200 text-sm dark:border-slate-700 dark:bg-navy-950">
                                            <option value="base" @selected(($item['use_base_unit'] ?? false))>{{ $selectedProduct?->unit?->short_name }} (base)</option>
                                            @foreach ($purchaseConversions as $purchaseConversion)
                                                <option value="{{ $purchaseConversion->id }}" @selected((string) ($item['product_unit_conversion_id'] ?? '') === (string) $purchaseConversion->id)>{{ $purchaseConversion->unit?->short_name }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <span class="font-bold">{{ $selectedPurchaseUnit?->short_name ?: '-' }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3">
                                    <input wire:model.blur="items.{{ $index }}.ordered_quantity" type="number" min="{{ $quantityStep }}" step="{{ $quantityStep }}" class="w-28 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950">
                                    @if ($selectedProduct)<span class="mt-1 block text-xs font-semibold text-slate-500">{{ $selectedPurchaseUnit?->short_name }}</span>@endif
                                    @error("items.{$index}.ordered_quantity") <span class="block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                                </td>
                                <td class="px-3 py-3 text-xs font-semibold">1 {{ $selectedPurchaseUnit?->short_name ?: '-' }} = {{ \App\Support\NumberFormatter::quantity($item['purchase_conversion_factor'] ?? 1) }} {{ $selectedProduct?->unit?->short_name }}</td>
                                <td class="px-3 py-3 font-bold">{{ \App\Support\NumberFormatter::quantity(($item['ordered_quantity'] ?? 0) * ($item['purchase_conversion_factor'] ?? 1)) }} {{ $selectedProduct?->unit?->short_name }}</td>
                                <td class="px-3 py-3">
                                    <span data-money-field wire:ignore class="block w-36" wire:key="purchase-cost-price-{{ $itemKey }}">
                                        <input type="text" inputmode="decimal" data-money-display class="w-full rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950">
                                        <input type="hidden" data-money-value value="{{ $item['cost_price'] ?? '' }}" wire:model.blur="items.{{ $index }}.cost_price">
                                    </span>
                                </td>
                                <td class="px-3 py-3">
                                    @if ($canUpdateSellingPrice)
                                        <span data-money-field wire:ignore class="block w-36" wire:key="purchase-selling-price-{{ $itemKey }}">
                                            <input type="text" inputmode="decimal" data-money-display class="w-full rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950">
                                            <input type="hidden" data-money-value value="{{ $sellingPriceValue }}" wire:model.blur="items.{{ $index }}.selling_price">
                                        </span>
                                    @else
                                        <div class="w-36 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 font-semibold text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
                                            TZS {{ \App\Support\NumberFormatter::money($sellingPriceValue) }}
                                        </div>
                                    @endif
                                </td>
                                <td class="px-3 py-3 font-black">TZS {{ \App\Support\NumberFormatter::moneyCompact($item['line_total'] ?? 0) }}</td>
                                <td class="px-3 py-3"><button type="button" wire:click="removeItem({{ $index }})" class="text-sm font-bold text-red-600">Remove</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <button type="button" wire:click="addItem" @disabled(blank($supplier_id)) class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-black disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700">Add Item</button>

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">Notes
                <textarea wire:model="notes" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
            </label>

            <div class="rounded-xl bg-slate-50 p-4 text-right dark:bg-white/5">
                <p class="text-sm text-slate-500">Subtotal</p>
                <p class="text-lg font-bold">TZS {{ \App\Support\NumberFormatter::moneyCompact($subtotal) }}</p>
                <p class="text-sm text-slate-500">Grand Total</p>
                <p class="text-2xl font-black">TZS {{ \App\Support\NumberFormatter::moneyCompact($grand_total) }}</p>
                <p class="text-sm text-slate-500">Paid</p>
                <p class="text-lg font-bold">TZS {{ \App\Support\NumberFormatter::moneyCompact($paid_amount) }}</p>
                <p class="text-sm text-slate-500">Balance: TZS {{ \App\Support\NumberFormatter::moneyCompact($balance_amount) }}</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <button type="submit" x-on:click="$wire.$set('save_status', 'draft', false); $wire.$set('send_purchase_email', false, false)" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Save as Draft</button>
                <button type="submit" x-on:click="$wire.$set('save_status', 'ordered', false); $wire.$set('send_purchase_email', false, false)" class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Save as Ordered</button>
                <button type="submit" x-on:click="$wire.$set('save_status', 'ordered', false); $wire.$set('send_purchase_email', true, false)" class="rounded-xl border border-orange-200 bg-orange-50 px-4 py-2.5 text-sm font-black text-build-orange dark:border-orange-500/30 dark:bg-orange-500/10">Save & Send PO</button>
                <a href="{{ route('purchases.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Cancel</a>
            </div>
        </form>
    </x-card>
</div>
