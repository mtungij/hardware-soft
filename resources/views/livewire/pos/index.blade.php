<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockLocation;
use App\Services\InventoryService;
use App\Services\LocationPriceService;
use App\Services\ProductUnitConversionService;
use App\Support\InventorySettings;
use App\Support\UiText;
use Illuminate\Validation\ValidationException;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'branch_id' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''),
    'stock_location_id' => '',
    'search' => '',
    'barcode' => '',
    'customer_id' => '',
    'sale_type' => 'retail',
    'discount_mode' => 'none',
    'order_discount_type' => 'fixed',
    'order_discount_value' => '0',
    'cart' => [],
    'payments' => [['payment_method' => 'cash', 'amount' => '0', 'reference_number' => '']],
    'notes' => '',
    'sale_date' => '',
    'backdate_confirmed' => false,
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
    'submission_token' => '',
    'processing' => false,
]);

mount(function (InventoryService $inventory) {
    $this->sale_date = app(\App\Support\PosSaleDate::class)->today(auth()->user()->company_id);
    $this->branch_id = (string) (auth()->user()->branch_id ?: Branch::where('code', 'MAIN')->value('id'));
    $allowedLocations = collect(InventorySettings::allowedSaleLocationsForUser(auth()->user(), (int) $this->branch_id));
    $setting = InventorySettings::current();

    if ($allowedLocations->count() === 1 || ($setting->inventory_mode ?? 'multi_location') === 'single_location') {
        $this->stock_location_id = (string) ($allowedLocations->first()?->id
            ?? (! InventorySettings::warehouseEnabled() ? $inventory->getDispensingLocation((int) $this->branch_id)->id : ''));
    }

    $this->quick_customer_branch_id = $this->branch_id;
    $this->submission_token = (string) str()->uuid();
});

$canCreditSale = fn () => auth()->user()->can('create credit sales') || auth()->user()->hasAnyRole(['Super Admin', 'Admin', 'Manager']);
$canCreateUnassignedCreditSale = fn () => auth()->user()->can('create unassigned credit sales') || auth()->user()->hasAnyRole(['Super Admin', 'Admin', 'Manager']);
$canApplyDiscount = fn () => auth()->user()->can('sales.discount');

$updatedDiscountMode = function ($mode) {
    if (! in_array($mode, ['none', 'item', 'order'], true) || ($mode !== 'none' && ! $this->canApplyDiscount())) {
        $this->discount_mode = 'none';
    }

    foreach ($this->cart as $index => $item) {
        $this->cart[$index]['discount_type'] = 'fixed';
        $this->cart[$index]['discount_value'] = '0';
        $this->cart[$index]['discount_amount'] = '0';
    }
    $this->order_discount_type = 'fixed';
    $this->order_discount_value = '0';
    $this->syncDefaultPaymentAmount();
};

$updatedOrderDiscountValue = fn () => $this->syncDefaultPaymentAmount();
$updatedOrderDiscountType = fn () => $this->syncDefaultPaymentAmount();

$allowedSaleLocations = fn () => collect(InventorySettings::allowedSaleLocationsForUser(auth()->user(), (int) $this->branch_id));

$currentSaleLocation = function () {
    $locations = $this->allowedSaleLocations();

    return $locations->firstWhere('id', (int) $this->stock_location_id);
};

$applyStockLocation = function ($locationId) {
    $this->stock_location_id = filled($locationId) ? (string) $locationId : '';
    $this->resetErrorBag(['stock_location_id', 'cart']);
};

$requestStockLocationChange = function ($locationId) {
    $this->applyStockLocation($locationId);
};

$priceForProduct = function (Product $product, ?int $conversionId = null, ?int $locationId = null): string {
    $location = $this->allowedSaleLocations()->firstWhere('id', $locationId ?: (int) $this->stock_location_id);
    $conversion = $conversionId
        ? app(ProductUnitConversionService::class)->resolveForSale($product, $conversionId)
        : null;
    $price = $location
        ? app(LocationPriceService::class)->priceFor($product, $location, $this->sale_type, $conversion)
        : null;

    return $price === null ? '' : number_format((float) $price, 2, '.', '');
};

$updatedSaleType = function () {
    foreach ($this->cart as $index => $item) {
        $product = Product::query()->find($item['product_id'] ?? null);

        if (! $product) {
            continue;
        }

        $unitPrice = $this->priceForProduct($product, filled($item['product_unit_conversion_id'] ?? null) ? (int) $item['product_unit_conversion_id'] : null, (int) ($item['stock_location_id'] ?? 0));

        if ($unitPrice === '') {
            $this->cart[$index]['unit_price'] = '0';
            $this->cart[$index]['tax_amount'] = '0';
            $this->dispatch('money-input-updated', model: "cart.{$index}.unit_price", value: '0');
            $this->dispatch('money-input-updated', model: "cart.{$index}.tax_amount", value: '0');

            continue;
        }

        $this->cart[$index]['sale_type'] = $this->sale_type;
        $this->cart[$index]['unit_price'] = $unitPrice;
        $this->cart[$index]['tax_amount'] = (string) app(InventoryService::class)->saleTaxPerUnit($product, (float) $unitPrice);
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
    session()->flash('success', UiText::translate('Customer created and selected.'));
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

$updatedCart = function ($value = null, $key = null) {
    $this->syncDefaultPaymentAmount();

    if (! is_string($key) || ! str_ends_with($key, '.quantity')) {
        return;
    }

    $index = (int) str($key)->before('.')->toString();
    $item = $this->cart[$index] ?? null;
    if (! $item || blank($item['stock_location_id'] ?? null)) {
        return;
    }

    $selected = $this->stockByLocationForProduct((int) $item['product_id'])
        ->firstWhere('id', (int) $item['stock_location_id']);
    $factor = max(0.0001, (float) ($item['conversion_factor'] ?? 1));
    $available = ($item['uses_direct_conversion'] ?? false)
        ? (float) ($selected['stock'] ?? 0) / $factor
        : (float) ($selected['stock'] ?? 0) * $factor;

    if ((float) $value > $available) {
        $this->addError("cart.{$index}.quantity", 'Quantity exceeds stock available at '.($item['stock_location_name'] ?? 'the selected location').'.');
    } else {
        $this->resetErrorBag("cart.{$index}.quantity");
    }
};

$locationBalancesForProduct = function (int $productId) {
    $preferredId = filled($this->stock_location_id) ? (int) $this->stock_location_id : null;

    return $this->allowedSaleLocations()
        ->map(function (StockLocation $location) use ($productId): array {
            return [
                'id' => $location->id,
                'name' => InventorySettings::stockLocationLabel($location),
                'stock' => app(InventoryService::class)->getProductStock($productId, $location->id, (int) $this->branch_id),
            ];
        })
        ->sortBy(fn (array $row): array => [$row['id'] === $preferredId ? 0 : 1, $row['name']])
        ->values();
};

$stockByLocationForProduct = function (int $productId) {
    return $this->locationBalancesForProduct($productId)
        ->filter(fn (array $row): bool => $row['stock'] > 0)
        ->values();
};

$changeLineLocation = function (int $index, $locationId): void {
    if (! isset($this->cart[$index])) {
        return;
    }
    $locationId = (int) $locationId;
    $productId = (int) $this->cart[$index]['product_id'];
    $locations = $this->stockByLocationForProduct($productId);
    $selected = $locations->firstWhere('id', $locationId);
    if (! $selected) {
        $this->addError("cart.{$index}.stock_location_id", 'Select an authorised location with available stock.');

        return;
    }
    $duplicate = collect($this->cart)->contains(fn (array $item, int $itemIndex): bool => $itemIndex !== $index
        && (int) $item['product_id'] === $productId
        && (int) ($item['stock_location_id'] ?? 0) === $locationId
        && (string) ($item['unit_selection'] ?? 'default') === (string) ($this->cart[$index]['unit_selection'] ?? 'default'));
    if ($duplicate) {
        $this->addError("cart.{$index}.stock_location_id", 'This product already has a cart line for that location.');

        return;
    }
    $this->cart[$index]['stock_location_id'] = $locationId;
    $this->cart[$index]['stock_location_name'] = $selected['name'];
    $product = Product::query()->findOrFail($productId);
    $price = $this->priceForProduct($product,
        filled($this->cart[$index]['product_unit_conversion_id'] ?? null) ? (int) $this->cart[$index]['product_unit_conversion_id'] : null,
        $locationId);
    if ($price === '') {
        $this->addError("cart.{$index}.unit_price", 'No '.$this->sale_type.' price is configured for this unit.');

        return;
    }
    $this->cart[$index]['unit_price'] = $price;
    $this->cart[$index]['tax_amount'] = (string) app(InventoryService::class)->saleTaxPerUnit($product, (float) $price);
    $this->dispatch('money-input-updated', model: "cart.{$index}.unit_price", value: $price);
    $this->syncDefaultPaymentAmount();

    $conversionFactor = max(0.0001, (float) ($this->cart[$index]['conversion_factor'] ?? 1));
    $available = ($this->cart[$index]['uses_direct_conversion'] ?? false)
        ? $selected['stock'] / $conversionFactor
        : $selected['stock'] * $conversionFactor;
    if ((float) $this->cart[$index]['quantity'] > $available) {
        $this->addError("cart.{$index}.quantity", 'Quantity exceeds stock available at '.$selected['name'].'.');
    } else {
        $this->resetErrorBag(["cart.{$index}.quantity", "cart.{$index}.stock_location_id"]);
    }
};

$changeLineUnit = function (int $index, string $selection): void {
    $item = $this->cart[$index] ?? null;
    $product = $item ? Product::query()->with(['unit', 'sellingUnit'])->find($item['product_id']) : null;

    if (! $product) {
        return;
    }

    $duplicate = collect($this->cart)->contains(fn (array $row, int $rowIndex): bool => $rowIndex !== $index
        && (int) ($row['product_id'] ?? 0) === (int) $product->id
        && (int) ($row['stock_location_id'] ?? 0) === (int) ($item['stock_location_id'] ?? 0)
        && (string) ($row['unit_selection'] ?? 'default') === $selection);

    if ($duplicate) {
        $this->addError("cart.{$index}.unit_price", 'This product, unit, and stock location already has a cart line.');

        return;
    }

    if ($selection === 'base') {
        $this->cart[$index]['product_unit_conversion_id'] = '';
        $this->cart[$index]['selling_unit_id'] = $product->unit_id;
        $this->cart[$index]['selling_unit'] = $product->unit?->short_name;
        $this->cart[$index]['conversion_factor'] = '1';
        $this->cart[$index]['uses_direct_conversion'] = true;
        $unitPrice = $this->priceForProduct($product, null, (int) ($item['stock_location_id'] ?? 0));
    } elseif ($selection === 'default') {
        $this->cart[$index]['product_unit_conversion_id'] = '';
        $this->cart[$index]['selling_unit_id'] = $product->selling_unit_id ?: $product->unit_id;
        $this->cart[$index]['selling_unit'] = $product->sellingUnit?->short_name ?: $product->unit?->short_name;
        $this->cart[$index]['conversion_factor'] = (string) $product->saleConversionFactor();
        $this->cart[$index]['uses_direct_conversion'] = false;
        $unitPrice = $this->priceForProduct($product, null, (int) ($item['stock_location_id'] ?? 0));
    } else {
        $conversion = app(ProductUnitConversionService::class)->resolveForSale($product, (int) $selection);
        $this->cart[$index]['product_unit_conversion_id'] = (string) $conversion->id;
        $this->cart[$index]['selling_unit_id'] = $conversion->unit_id;
        $this->cart[$index]['selling_unit'] = $conversion->unit?->short_name;
        $this->cart[$index]['conversion_factor'] = (string) $conversion->conversion_factor;
        $this->cart[$index]['uses_direct_conversion'] = true;
        $unitPrice = $this->priceForProduct($product, $conversion->id, (int) ($item['stock_location_id'] ?? 0));
    }

    if ($unitPrice === '') {
        $this->addError("cart.{$index}.unit_price", 'No '.$this->sale_type.' price is configured for this unit.');

        return;
    }

    $this->cart[$index]['unit_selection'] = $selection;
    $this->cart[$index]['unit_price'] = $unitPrice;
    $this->cart[$index]['tax_amount'] = (string) app(InventoryService::class)->saleTaxPerUnit($product, (float) $unitPrice);
    $this->resetErrorBag(["cart.{$index}.unit_price", "cart.{$index}.quantity"]);
    $this->syncDefaultPaymentAmount();
};

$addProduct = function (int $productId, $locationId = null) {
    $locations = $this->stockByLocationForProduct($productId);
    if ($locations->isEmpty()) {
        $this->addError('cart', __('application.this_product_has_no_stock_in_an_authorised_selling_location_e9e0b0d4'));

        return;
    }
    if (filled($locationId)) {
        $selectedLocation = $locations->firstWhere('id', (int) $locationId);
        if (! $selectedLocation) {
            $this->addError('cart', __('application.select_an_authorised_location_with_available_stock_a9122fba'));

            return;
        }
    } elseif ($locations->count() === 1) {
        $selectedLocation = $locations->first();
    } else {
        $this->addError('cart', __('application.select_the_source_location_for_this_product_3003b6d9'));

        return;
    }
    $this->resetErrorBag('cart');

    $product = Product::query()->with(['category', 'measurementType', 'unit', 'sellingUnit', 'size', 'unitConversions.unit'])->findOrFail($productId);
    $supportsFractionalSales = $product->allowsDecimalQuantities();
    $conversionFactor = $product->saleConversionFactor();
    $baseStock = (float) $selectedLocation['stock'];
    $available = $baseStock * $conversionFactor;

    if ($available <= 0) {
        $this->addError('cart', UiText::translate('Product is out of stock in selected source.'));

        return;
    }

    $unitPrice = $this->priceForProduct($product, null, (int) $selectedLocation['id']);

    if ($unitPrice === '') {
        $this->addError('cart', UiText::translate('Wholesale price is not set for this product.'));

        return;
    }

    foreach ($this->cart as $index => $item) {
        if ((int) $item['product_id'] === $productId && (int) ($item['stock_location_id'] ?? 0) === (int) $selectedLocation['id'] && ($item['unit_selection'] ?? 'default') === 'default') {
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
        'stock_location_id' => (int) $selectedLocation['id'],
        'stock_location_name' => $selectedLocation['name'],
        'name' => $product->displayName(),
        'size' => $product->sizeLabel(),
        'sku' => $product->sku,
        'sale_type' => $this->sale_type,
        'unit_selection' => 'default',
        'product_unit_conversion_id' => '',
        'selling_unit_id' => $product->selling_unit_id ?: $product->unit_id,
        'uses_direct_conversion' => false,
        'quantity' => $supportsFractionalSales
            ? (string) ($product->minimum_sale_quantity ?: ($product->quantity_step ?: 1))
            : '1',
        'unit_price' => $unitPrice,
        'discount_amount' => '0',
        'discount_type' => 'fixed',
        'discount_value' => '0',
        'tax_amount' => (string) app(InventoryService::class)->saleTaxPerUnit($product, (float) $unitPrice),
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

$itemDiscountFor = function (array $item): float {
    if ($this->discount_mode !== 'item') {
        return 0;
    }

    $gross = (float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0);
    $value = max(0, (float) ($item['discount_value'] ?? 0));

    return ($item['discount_type'] ?? 'fixed') === 'percentage'
        ? round($gross * min(100, $value) / 100, 2)
        : min($gross, $value);
};

$discountTotal = function (): float {
    $subtotal = (float) $this->subtotal();
    if ($this->discount_mode === 'item') {
        return (float) collect($this->cart)->sum(fn (array $item) => $this->itemDiscountFor($item));
    }
    if ($this->discount_mode === 'order') {
        $value = max(0, (float) $this->order_discount_value);

        return ($this->order_discount_type === 'percentage')
            ? round($subtotal * min(100, $value) / 100, 2)
            : min($subtotal, $value);
    }

    return 0;
};

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
        $this->addError('customer_id', UiText::translate('Select a customer or create a customer before completing this credit sale.'));

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

$resetCompletedSale = function () {
    $this->sale_date = $this->companyToday();
    $this->backdate_confirmed = false;
    $this->cart = [];
    $this->payments = [['payment_method' => 'cash', 'amount' => '0', 'reference_number' => '']];
    $this->customer_id = '';
    $this->sale_type = 'retail';
    $this->discount_mode = 'none';
    $this->order_discount_type = 'fixed';
    $this->order_discount_value = '0';
    $this->notes = '';
    $this->temporary_customer_name = '';
    $this->temporary_customer_phone = '';
    $this->project_name = '';
    $this->vehicle_number = '';
    $this->expected_payment_date = '';
    $this->credit_notes = '';
    $this->unassigned_credit_confirmed = false;
    $this->auto_payment_amount = '0';
    $this->payment_amount_manually_edited = false;
    $this->submission_token = (string) str()->uuid();
};

$companyToday = fn (): string => app(\App\Support\PosSaleDate::class)->today(auth()->user()->company_id);
$canBackdate = fn (): bool => app(\App\Support\PosSaleDate::class)->canBackdate(auth()->user());
$resetSaleDate = function (): void { $this->sale_date = $this->companyToday(); $this->backdate_confirmed = false; };
$updatedSaleDate = function (): void { $this->backdate_confirmed = false; };

$completeSale = function (InventoryService $inventory) {
    if ($this->processing) {
        return;
    }

    $this->processing = true;

    try {
        $fallbackLocationId = filled($this->stock_location_id)
            ? (int) $this->stock_location_id
            : (int) ($this->allowedSaleLocations()->first()?->id ?? 0);
        foreach ($this->cart as $index => $item) {
            $this->cart[$index]['sale_type'] = $this->sale_type;
            if (blank($item['stock_location_id'] ?? null) && $fallbackLocationId) {
                $this->cart[$index]['stock_location_id'] = $fallbackLocationId;
            }

            // Base/default selling unit is represented as null, never an empty string,
            // so the "nullable" rule below actually skips the exists check for it.
            if (($item['product_unit_conversion_id'] ?? null) === '') {
                $this->cart[$index]['product_unit_conversion_id'] = null;
            }
        }
        $this->validate([
            'sale_date' => ['required', 'date_format:Y-m-d'],
            'backdate_confirmed' => ['boolean'],
            'stock_location_id' => ['nullable', 'exists:stock_locations,id'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'cart' => ['required', 'array', 'min:1'],
            'cart.*.product_id' => ['required', 'exists:products,id'],
            'cart.*.product_unit_conversion_id' => ['nullable', 'exists:product_unit_conversions,id'],
            'cart.*.stock_location_id' => ['required', 'integer', 'exists:stock_locations,id'],
            'cart.*.sale_type' => ['required', 'in:retail,wholesale'],
            'cart.*.quantity' => ['required', 'numeric', 'gt:0'],
            'discount_mode' => ['required', 'in:none,item,order'],
            'order_discount_type' => ['required_if:discount_mode,order', 'in:fixed,percentage'],
            'order_discount_value' => ['required_if:discount_mode,order', 'numeric', 'min:0'],
            'cart.*.discount_type' => ['required_if:discount_mode,item', 'in:fixed,percentage'],
            'cart.*.discount_value' => ['required_if:discount_mode,item', 'numeric', 'min:0'],
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

        if ($this->discount_mode !== 'none' && ! $this->canApplyDiscount()) {
            throw ValidationException::withMessages(['discount_mode' => __('application.you_are_not_authorized_to_apply_discounts_ba8e3486')]);
        }
        if ($this->discount_mode === 'order' && $this->order_discount_type === 'percentage' && (float) $this->order_discount_value > 100) {
            throw ValidationException::withMessages(['order_discount_value' => __('application.discount_percentage_cannot_exceed_100_d65f6729')]);
        }
        if ($this->discount_mode === 'order' && (float) $this->order_discount_value > $this->subtotal() && $this->order_discount_type === 'fixed') {
            throw ValidationException::withMessages(['order_discount_value' => __('application.order_discount_cannot_exceed_the_subtotal_2c684d13')]);
        }
        foreach ($this->cart as $index => $item) {
            $gross = (float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0);
            if ($this->discount_mode === 'item' && ($item['discount_type'] ?? null) === 'percentage' && (float) ($item['discount_value'] ?? 0) > 100) {
                throw ValidationException::withMessages(["cart.{$index}.discount_value" => __('application.discount_percentage_cannot_exceed_100_d65f6729')]);
            }
            if ($this->discount_mode === 'item' && ($item['discount_type'] ?? null) === 'fixed' && (float) ($item['discount_value'] ?? 0) > $gross) {
                throw ValidationException::withMessages(["cart.{$index}.discount_value" => __('application.discount_cannot_exceed_the_line_subtotal_7ddcdf36')]);
            }
        }

        if (collect($this->payments)->contains(fn ($payment) => $payment['payment_method'] === 'credit') && ! $this->canCreditSale()) {
            throw ValidationException::withMessages(['payments' => UiText::translate('You are not authorized to create credit sales.')]);
        }

        if ($this->usesCreditPayment() && blank($this->customer_id) && ! $this->unassigned_credit_confirmed) {
            if (! (bool) (InventorySettings::current()->allow_credit_sale_without_customer ?? true) || ! $this->canCreateUnassignedCreditSale()) {
                throw ValidationException::withMessages(['customer_id' => UiText::translate('Select a customer or create a customer before completing this credit sale.')]);
            }

            $this->dispatch('open-modal', 'unassigned-credit-warning');

            return;
        }

        $sale = $inventory->completeSale(
            $this->cart,
            $this->payments,
            $this->customer_id ? (int) $this->customer_id : null,
            (int) ($this->stock_location_id ?: $this->cart[0]['stock_location_id']),
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
            $this->submission_token,
            dateDetails: ['sale_date' => $this->sale_date, 'backdate_confirmed' => $this->backdate_confirmed],
            discountDetails: [
                'mode' => $this->discount_mode,
                'type' => $this->order_discount_type,
                'value' => $this->order_discount_value,
            ],
        );

        $this->resetCompletedSale();
        $this->redirectRoute('sales.receipt', $sale, navigate: true);
    } catch (ValidationException $exception) {
        $message = $exception->validator->errors()->first()
            ?: __('feedback.sale_incomplete');

        // Re-declare the field errors ourselves (instead of letting the exception
        // propagate) so we can also guarantee a "sale" fallback error: Livewire's
        // own ValidationException handling replaces the *entire* error bag with
        // $exception->validator->errors(), which would silently drop it.
        foreach ($exception->validator->errors()->messages() as $key => $messages) {
            foreach ($messages as $fieldMessage) {
                $this->addError($key, $fieldMessage);
            }
        }
        $this->addError('sale', $message);

        // session flash alone is invisible here: it renders in the layout, which is
        // outside the Livewire component's DOM and isn't touched by this AJAX response.
        session()->flash('error', $message);
    } catch (Throwable $exception) {
        report($exception);
        $message = __('feedback.sale_incomplete').' '.\App\Support\UiText::feedback($exception->getMessage());
        $this->addError('sale', $message);
        session()->flash('error', $message);
    } finally {
        $this->processing = false;
    }
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
        $locationReady = $allowedLocations->isNotEmpty();
        $selectedCustomer = $customer_id ? Customer::query()->find($customer_id) : null;
        $remainingCredit = max(0, $this->grandTotal() - $this->paidTotal());
    @endphp

    <x-page-header title="{{ __('application.pos_sales_450223da') }}" description="{{ __('application.build_one_sale_from_any_authorised_selling_location_7444c542') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'POS Sales' => null]" />

    <div class="grid gap-6 xl:grid-cols-[1fr_440px]">
        <div class="space-y-5">
            <x-card>
                <div class="grid gap-3 md:grid-cols-3">
                    <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="{{ $t(__('application.search_products_b73f756b')) }}">
                    <input wire:model="barcode" wire:keydown.enter="addBarcode" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-700 dark:bg-white/5" placeholder="{{ $t(__('application.barcode_input_1f27b2ce')) }}" @disabled(! $locationReady)>
                    @if ($allowedLocations->count() > 1)
                        <label class="block text-xs font-black uppercase tracking-wide text-slate-500 dark:text-slate-400" for="selling-location">
                            {{ __('application.preferred_location_b9aee26e') }}
                            <select id="selling-location" wire:change="requestStockLocationChange($event.target.value)" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-normal normal-case tracking-normal text-slate-900 dark:border-slate-700 dark:bg-navy-950 dark:text-white" aria-label="{{ $t(__('application.selling_from_6a9ed70b')) }}">
                                <option value="" @selected(blank($stock_location_id))>{{ __('application.all_authorised_locations_f976dd0d') }}</option>
                                @foreach ($allowedLocations as $location)
                                    <option value="{{ $location->id }}" @selected((string) $stock_location_id === (string) $location->id)>{{ InventorySettings::stockLocationLabel($location) }}</option>
                                @endforeach
                            </select>
                        </label>
                    @else
                        <div class="rounded-lg border border-cyan-200 bg-cyan-50 px-3 py-3 text-sm font-bold text-cyan-800 dark:border-cyan-500/30 dark:bg-cyan-500/10 dark:text-cyan-100">
                            {{ __('application.preferred_location_f2280795') }} {{ $selectedSaleLocation ? InventorySettings::stockLocationLabel($selectedSaleLocation) : ($allowedLocations->first() ? InventorySettings::stockLocationLabel($allowedLocations->first()) : __('terminology.no_authorised_selling_locations_77e5c9a0')) }}
                        </div>
                    @endif
                </div>
                @error('stock_location_id') <p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
            </x-card>

            <x-card data-pos-section="price-mode">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm font-black text-slate-700 dark:text-slate-200">{{ __('application.price_type_5fbd28cf') }}</p>
                        <span class="mt-1 inline-flex rounded-full px-3 py-1 text-xs font-black {{ $saleTypeBadge }}">{{ \App\Support\UiText::label($sale_type) }}</span>
                    </div>
                    <div class="inline-flex rounded-lg border border-slate-200 bg-white p-1 dark:border-slate-700 dark:bg-navy-950">
                        <label class="cursor-pointer rounded-md px-3 py-2 text-sm font-black {{ $sale_type === 'retail' ? 'bg-blue-500 text-white' : 'text-slate-600 dark:text-slate-300' }}">
                            <input type="radio" wire:model.live="sale_type" value="retail" class="sr-only">
                            {{ __('application.retail_925dd33f') }}
                        </label>
                        <label class="cursor-pointer rounded-md px-3 py-2 text-sm font-black {{ $sale_type === 'wholesale' ? 'bg-emerald-500 text-white' : 'text-slate-600 dark:text-slate-300' }}">
                            <input type="radio" wire:model.live="sale_type" value="wholesale" class="sr-only">
                            {{ __('application.wholesale_21c1aef4') }}
                        </label>
                    </div>
                </div>
                @error('sale_type') <p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
            </x-card>

            @php
                $products = $locationReady
                    ? Product::with(['category', 'measurementType', 'unit', 'sellingUnit', 'size', 'unitConversions.unit'])
                        ->where('status', 'active')
                        ->when($search, fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")->orWhere('barcode', 'like', "%{$search}%")->orWhereHas('size', fn ($size) => $size->where('name', 'like', "%{$search}%")->orWhere('symbol', 'like', "%{$search}%"))))
                        ->orderBy('name')
                        ->take(24)
                        ->get()
                    : collect();
            @endphp
            @unless ($locationReady)
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                    {{ __('application.no_authorised_selling_locations_are_assigned_to_your_account_6b20d34a') }}
                </div>
            @endunless
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($products as $product)
                    @php
                        $displayPrice = ($selectedPriceLocation = $this->currentSaleLocation())
                            ? app(LocationPriceService::class)->priceFor($product, $selectedPriceLocation, $sale_type)
                            : null;
                        $supportsFractionalSales = $product->allowsDecimalQuantities();
                        $sellingUnitLabel = $product->sellingUnit?->short_name ?: $product->unit?->short_name;
                        $baseUnitLabel = $product->unit?->short_name;
                        $conversionFactor = $product->saleConversionFactor();
                        $locationBalances = $this->locationBalancesForProduct($product->id);
                        $showsBaseStock = $product->usesUnitConversion() && $baseUnitLabel && ($sellingUnitLabel !== $baseUnitLabel || abs($conversionFactor - 1) > 0.0001);
                        $productInitials = \App\Support\ProductAvatar::wordInitials($product->name);
                    @endphp
                    <article wire:key="pos-product-{{ $product->id }}" class="flex h-full min-w-0 flex-col rounded-[18px] border border-[#E5E7EB] bg-white p-4 text-left shadow-[0_8px_25px_rgba(15,23,42,0.08)] transition duration-200 ease-out motion-safe:hover:-translate-y-1 hover:shadow-[0_15px_40px_rgba(0,0,0,0.12)]">
                        <x-product-image
                            :product="$product"
                            :initials="$productInitials"
                            class="h-[130px] w-full"
                            frame-class="rounded-[12px]"
                            fallback-class="bg-[#FF6A00] text-white"
                            initials-class="text-[56px] font-bold leading-none tracking-[2px]"
                        />

                        <div class="mt-3.5 min-w-0">
                            <p class="line-clamp-2 text-[20px] font-bold leading-6 text-[#111827]">{{ $product->displayName() }}</p>
                            <p class="mt-1 text-[14px] text-[#6B7280]">{{ $sellingUnitLabel ?: '-' }} · {{ $product->measurementType?->name ?? str($product->measurementCode())->title() }}</p>
                            @php $alsoSoldBy = $product->unitConversions->filter(fn ($row) => $row->active && $row->can_sell)->pluck('unit.short_name')->filter()->join(', '); @endphp
                            @if ($alsoSoldBy)<p class="mt-0.5 text-[12px] font-semibold text-cyan-700">{{ __('application.also_sold_by_5af202d2') }} {{ $alsoSoldBy }}</p>@endif
                            @if ($product->sizeLabel())
                                <p class="mt-0.5 text-[13px] font-semibold text-[#6B7280]">{{ $t(__('application.size_b7152342')) }}: {{ $product->sizeLabel() }}</p>
                            @endif
                            <p class="mt-1 truncate text-[13px] text-[#6B7280]">{{ __('application.sku_92c95a18') }} {{ $product->sku ?: '-' }}</p>
                        </div>

                        @if ($selectedPriceLocation)
                            <div class="mt-3">
                                @if ($displayPrice !== null)
                                    <span class="text-[28px] font-extrabold leading-none text-[#00B5E2]">
                                        TZS {{ \App\Support\NumberFormatter::money($displayPrice) }}
                                    </span>
                                @else
                                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
                                        <p class="text-sm font-black text-amber-700">{{ __('application.price_not_set_40a3da1e') }}</p>
                                        <p class="mt-0.5 text-xs font-semibold text-amber-600">
                                            {{ __('application.set_448ab73b') }} {{ \App\Support\UiText::label($sale_type) }} {{ __('application.price_for_f03e8721') }} {{ $selectedPriceLocation->name }} {{ __('application.in_location_pricing_ff622050') }}
                                        </p>
                                    </div>
                                @endif
                            </div>
                        @endif

                        <div class="mt-auto space-y-2 pt-4">
                            @forelse($locationBalances as $balance)
                                @php
                                    $sellingStock = $balance['stock'] * $conversionFactor;
                                    $rowLocation = $this->allowedSaleLocations()->firstWhere('id', (int) $balance['id']);
                                    $rowPrice = $rowLocation
                                        ? app(LocationPriceService::class)->priceFor($product, $rowLocation, $sale_type)
                                        : null;
                                    $stockStateClasses = $balance['stock'] <= 0
                                        ? 'bg-[#FEE2E2] text-[#DC2626]'
                                        : ($balance['stock'] <= (float) $product->reorder_level
                                            ? 'bg-[#FEF3C7] text-[#D97706]'
                                            : 'bg-[#DCFCE7] text-[#15803D]');
                                @endphp
                                <button
                                    type="button"
                                    wire:click="addProduct({{ $product->id }}, {{ $balance['id'] }})"
                                    wire:loading.attr="disabled"
                                    wire:target="addProduct"
                                    @disabled($balance['stock'] <= 0 || $rowPrice === null)
                                    class="flex w-full items-center justify-between gap-3 rounded-[10px] border border-[#E5E7EB] bg-white px-3 py-2 text-left text-[13px] transition hover:border-[#00B5E2] hover:bg-sky-50 disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:border-[#E5E7EB] disabled:hover:bg-white"
                                >
                                    <span class="min-w-0">
                                        <span class="block truncate font-semibold text-[#374151]">{{ $balance['name'] }}</span>
                                        @if ($rowPrice !== null)
                                            <span class="block text-xs font-bold text-[#00B5E2]">
                                                TZS {{ \App\Support\NumberFormatter::money($rowPrice) }}
                                            </span>
                                        @else
                                            <span class="block text-xs font-bold text-amber-600">
                                                {{ __('application.price_not_set_40a3da1e') }}
                                            </span>
                                        @endif
                                    </span>
                                    <span class="shrink-0 rounded-md px-2 py-1 font-bold {{ $stockStateClasses }}">{{ \App\Support\NumberFormatter::quantity($sellingStock) }} {{ $sellingUnitLabel }}</span>
                                </button>
                            @empty
                                <p class="rounded-[10px] bg-[#FEE2E2] px-3 py-2 text-[13px] font-bold text-[#DC2626]">{{ __('application.no_stock_in_an_authorised_selling_location_27ad9144') }}</p>
                            @endforelse
                        </div>
                        @if ($showsBaseStock)
                            <p class="mt-2 rounded-lg bg-cyan-50 px-2 py-1 text-[11px] font-bold text-cyan-700">
                                1 {{ $baseUnitLabel }} = {{ \App\Support\NumberFormatter::quantity($conversionFactor) }} {{ $sellingUnitLabel }}
                            </p>
                        @endif
                        @if ($supportsFractionalSales)
                            <span class="mt-2 inline-flex rounded-full bg-cyan-100 px-2 py-0.5 text-[11px] font-black text-cyan-700">{{ $t(__('terminology.fractional_sale_d1af78e2')) }}</span>
                        @endif
                    </article>
                @endforeach
            </div>
        </div>

        <x-card title="{{ __('application.cart_payment_66e3b030') }}" class="xl:sticky xl:top-24 xl:max-h-[calc(100vh-7rem)] xl:overflow-y-auto">
            <div class="space-y-3">
                <div class="flex gap-2">
                    <select wire:model="customer_id" class="min-w-0 flex-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        <option value="">{{ $t(__('application.walk_in_customer_90cd7712')) }}</option>
                        @foreach (Customer::where('status', 'active')->where(fn ($query) => $query->where('is_system_customer', false)->orWhereNull('is_system_customer'))->orderBy('name')->get() as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->displayName() }} / {{ \App\Support\UiText::label($customer->customer_type) }}</option>
                        @endforeach
                    </select>
                    <button type="button" wire:click="openQuickCustomerModal" class="grid h-10 w-10 shrink-0 place-items-center rounded-lg border border-cyan-200 bg-cyan-50 text-xl font-black leading-none text-cyan-700 transition hover:border-cyan-400 hover:bg-cyan-100 dark:border-cyan-500/30 dark:bg-cyan-500/10 dark:text-cyan-200" title="{{ $t(__('application.create_customer_2bd83aec')) }}" aria-label="{{ $t(__('application.create_customer_2bd83aec')) }}">
                        +
                    </button>
                </div>
                @error('customer_id') <p class="text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
                <section data-pos-section="sale-date" class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                    <label for="pos-sale-date" class="block text-xs font-black uppercase tracking-wide text-slate-600 dark:text-slate-300">{{ __('sale_dates.label') }}</label>
                    <div class="mt-2 flex gap-2">
                        <input id="pos-sale-date" type="date" wire:model.live="sale_date" max="{{ $this->companyToday() }}" @disabled(! $this->canBackdate()) class="min-w-0 flex-1 rounded-lg border-slate-200 disabled:bg-slate-100 dark:border-slate-700 dark:bg-navy-950">
                        <button type="button" wire:click="resetSaleDate" class="rounded-lg border px-3 py-2 text-sm font-bold">{{ __('sale_dates.today') }}</button>
                    </div>
                    @error('sale_date') <p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
                    @if ($sale_date < $this->companyToday())
                        <p class="mt-3 text-sm text-amber-700 dark:text-amber-300">{{ __('sale_dates.warning') }}</p>
                        <p class="mt-2 text-xs text-slate-500">{{ __('sale_dates.posting_help') }}</p>
                        <label class="mt-3 flex items-start gap-2 text-sm font-bold"><input type="checkbox" wire:model="backdate_confirmed" class="mt-1 rounded border-slate-300">{{ __('sale_dates.confirm') }}</label>
                        @error('backdate_confirmed') <p class="mt-2 text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
                    @endif
                </section>


                <section data-pos-section="cart-discount-mode">
                    <label class="block text-xs font-black uppercase tracking-wide text-slate-600 dark:text-slate-300">
                        {{ __('application.discount_type_ab547ca1') }}
                    @if ($this->canApplyDiscount())
                        <select data-discount-mode-selector wire:model.live="discount_mode" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 dark:border-slate-700 dark:bg-navy-950 dark:text-white">
                            <option value="none">{{ __('application.none_6eef6648') }}</option>
                            <option value="item">{{ __('application.per_item_675fd46e') }}</option>
                            <option value="order">{{ __('application.sale_total_869b9ea6') }}</option>
                        </select>
                    @else
                        <select disabled aria-disabled="true" class="mt-1 block w-full cursor-not-allowed rounded-lg border border-slate-200 bg-slate-100 px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                            <option>{{ __('application.none_6eef6648') }}</option>
                        </select>
                    @endif
                    </label>
                    @error('discount_mode') <p class="mt-2 text-xs font-semibold text-red-600">{{ $message }}</p> @enderror
                </section>

                @if ($this->usesCreditPayment())
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                        @if ($selectedCustomer)
                            <div class="flex justify-between gap-3"><span>{{ $t(__('application.customer_0e85749a')) }}</span><span class="font-black text-right">{{ $selectedCustomer->name }}</span></div>
                            <div class="mt-1 flex justify-between gap-3"><span>{{ $t(__('terminology.current_outstanding_balance_6ac2c36b')) }}</span><span class="font-black">TZS {{ \App\Support\NumberFormatter::money($selectedCustomer->balance_amount) }}</span></div>
                        @else
                            <span class="inline-flex rounded-full bg-amber-200 px-2.5 py-1 text-xs font-black text-amber-900 dark:bg-amber-400/20 dark:text-amber-100">{{ $t(__('terminology.unassigned_credit_sale_4751f151')) }}</span>
                        @endif
                        <div class="mt-2 grid gap-1">
                            <div class="flex justify-between gap-3"><span>{{ $t(__('application.sale_total_869b9ea6')) }}</span><span class="font-black">TZS {{ \App\Support\NumberFormatter::money($this->grandTotal()) }}</span></div>
                            <div class="flex justify-between gap-3"><span>{{ $t(__('terminology.amount_paid_now_f0f8eccc')) }}</span><span class="font-black">TZS {{ \App\Support\NumberFormatter::money($this->paidTotal()) }}</span></div>
                            <div class="flex justify-between gap-3"><span>{{ $t(__('terminology.remaining_credit_a500029f')) }}</span><span class="font-black">TZS {{ \App\Support\NumberFormatter::money($remainingCredit) }}</span></div>
                        </div>
                    </div>
                @endif

                @foreach ($cart as $index => $item)
                    @php
                        $quantity = (float) ($item['quantity'] ?? 0);
                        $unitPrice = (float) ($item['unit_price'] ?? 0);
                        $lineDiscount = $this->itemDiscountFor($item);
                        $taxPerUnit = (float) ($item['tax_amount'] ?? 0);
                        $sellingUnitLabel = $item['selling_unit'] ?? '';
                        $baseUnitLabel = $item['base_unit'] ?? '';
                        $isFractionalSale = (bool) ($item['allow_fractional_sale'] ?? false);
                        $conversionFactor = max(0.0001, (float) ($item['conversion_factor'] ?? 1));
                        $usesDirectConversion = (bool) ($item['uses_direct_conversion'] ?? false);
                        $baseQuantity = $usesDirectConversion ? $quantity * $conversionFactor : $quantity / $conversionFactor;
                        $lineLocationBalances = $this->stockByLocationForProduct((int) ($item['product_id'] ?? 0));
                        $selectedLineLocation = $lineLocationBalances->firstWhere('id', (int) ($item['stock_location_id'] ?? 0));
                        $availableBaseQuantity = (float) ($selectedLineLocation['stock'] ?? 0);
                        $availableSellingQuantity = $usesDirectConversion ? $availableBaseQuantity / $conversionFactor : $availableBaseQuantity * $conversionFactor;
                        $lineProduct = Product::query()->with(['unit', 'sellingUnit', 'unitConversions.unit'])->find($item['product_id'] ?? null);
                        $lineConversions = $lineProduct?->unitConversions?->filter(fn ($row) => $row->active && $row->can_sell) ?? collect();
                        $quantityStep = (string) ($item['quantity_step'] ?? 1);
                        $minimumQuantity = (string) ($item['minimum_sale_quantity'] ?? 1);
                    @endphp
                    <div class="rounded-lg bg-slate-50 p-3 dark:bg-white/5">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-bold">{{ $item['name'] }}</p>
                                @if (! empty($item['size']))
                                    <p class="text-xs font-bold text-cyan-700 dark:text-cyan-200">{{ $t(__('application.size_b7152342')) }}: {{ $item['size'] }}</p>
                                @endif
                                <p class="text-xs text-slate-500">{{ $item['sku'] }} / {{ $sellingUnitLabel }}</p>
                                <p class="text-[11px] font-bold text-slate-500">{{ $item['measurement_type'] ?? __('terminology.count_66e12969') }}</p>
                                <div class="mt-1 flex flex-wrap gap-1.5">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-black {{ ($item['sale_type'] ?? 'retail') === 'wholesale' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-blue-100 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' }}">{{ \App\Support\UiText::label($item['sale_type'] ?? 'retail') }}</span>
                                    @if ($isFractionalSale)
                                        <span class="inline-flex rounded-full bg-cyan-100 px-2 py-0.5 text-[11px] font-black text-cyan-700 dark:bg-cyan-500/10 dark:text-cyan-200">{{ $t(__('terminology.fractional_5de5f3ad')) }}</span>
                                    @endif
                                </div>
                            </div>
                            <button wire:click="removeItem({{ $index }})" class="text-xs font-bold text-red-600">{{ $t(__('application.remove_e963907d')) }}</button>
                        </div>
                        <label class="mt-3 block min-w-0 text-[11px] font-black uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            {{ __('application.selling_unit_293a9282') }}
                            <select wire:change="changeLineUnit({{ $index }}, $event.target.value)" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-2 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 dark:border-slate-700 dark:bg-navy-950 dark:text-white">
                                <option value="default" @selected(($item['unit_selection'] ?? 'default') === 'default')>{{ $lineProduct?->sellingUnit?->short_name ?: $lineProduct?->unit?->short_name }} {{ __('application.default_c8283858') }}</option>
                                @if ((int) $lineProduct?->selling_unit_id !== (int) $lineProduct?->unit_id)<option value="base" @selected(($item['unit_selection'] ?? '') === 'base')>{{ $lineProduct?->unit?->short_name }} {{ __('application.base_5ee057cb') }}</option>@endif
                                @foreach ($lineConversions as $lineConversion)
                                    <option value="{{ $lineConversion->id }}" @selected((string) ($item['unit_selection'] ?? '') === (string) $lineConversion->id)>{{ $lineConversion->unit?->short_name }} · 1 = {{ \App\Support\NumberFormatter::quantity($lineConversion->conversion_factor) }} {{ $lineProduct?->unit?->short_name }}</option>
                                @endforeach
                            </select>
                            @error("cart.{$index}.unit_price")<span class="mt-1 block text-xs font-semibold normal-case text-red-600">{{ $message }}</span>@enderror
                            @error("cart.{$index}.product_unit_conversion_id")<span class="mt-1 block text-xs font-semibold normal-case text-red-600">{{ $message }}</span>@enderror
                        </label>
                        <label class="mt-3 block min-w-0 text-[11px] font-black uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            {{ __('application.selling_from_6a9ed70b') }}
                            <select wire:change="changeLineLocation({{ $index }}, $event.target.value)" wire:loading.attr="disabled" wire:target="changeLineLocation" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-2 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 disabled:cursor-wait disabled:opacity-60 dark:border-slate-700 dark:bg-navy-950 dark:text-white">
                                @foreach($lineLocationBalances as $locationBalance)
                                    <option value="{{ $locationBalance['id'] }}" @selected((int)($item['stock_location_id'] ?? 0)===(int)$locationBalance['id'])>{{ $locationBalance['name'] }} · {{ \App\Support\NumberFormatter::quantity($usesDirectConversion ? $locationBalance['stock'] / $conversionFactor : $locationBalance['stock'] * $conversionFactor) }} {{ $sellingUnitLabel }}</option>
                                @endforeach
                            </select>
                            <span class="mt-1 block text-[11px] font-bold normal-case tracking-normal text-emerald-700 dark:text-emerald-300">{{ __('application.stock_at_cbfdc0dd') }} {{ $selectedLineLocation['name'] ?? ($item['stock_location_name'] ?? 'selected location') }}: {{ \App\Support\NumberFormatter::quantity($availableSellingQuantity) }} {{ $sellingUnitLabel }}</span>
                            @error("cart.{$index}.stock_location_id")<span class="mt-1 block text-xs font-semibold normal-case tracking-normal text-red-600">{{ $message }}</span>@enderror
                        </label>
                        <div class="mt-3 grid grid-cols-1 gap-2 min-[380px]:grid-cols-2">
                            <label class="block min-w-0 text-[11px] font-black uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                {{ $t(__('application.qty_1e5ff9e5')) }} ({{ $sellingUnitLabel ?: '-' }})
                                <input wire:model.live.debounce.400ms="cart.{{ $index }}.quantity" type="number" inputmode="decimal" step="{{ $isFractionalSale ? '0.0001' : '1' }}" min="{{ $minimumQuantity }}" class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-1 text-sm font-semibold normal-case tracking-normal text-slate-900 dark:border-slate-700 dark:bg-navy-950 dark:text-white">
                            </label>
                            <label class="block min-w-0 text-[11px] font-black uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                {{ $t(__('application.unit_price_1eea68d0')) }}
                                <input type="text" value="TZS {{ \App\Support\NumberFormatter::money($unitPrice) }}" readonly aria-readonly="true" class="mt-1 w-full cursor-not-allowed rounded-lg border border-slate-200 bg-slate-100 px-2 py-1 text-sm font-bold normal-case tracking-normal text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                            </label>
                            @if ($this->canApplyDiscount() && $discount_mode === 'item')
                                <div data-item-discount-editor class="contents">
                                    <label class="block min-w-0 text-[11px] font-black uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                        {{ __('application.discount_type_ab547ca1') }}
                                        <select wire:model.live="cart.{{ $index }}.discount_type" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-1 text-sm font-semibold normal-case tracking-normal dark:border-slate-700 dark:bg-navy-950">
                                            <option value="fixed">{{ __('application.amount_43dc8532') }}</option>
                                            <option value="percentage">{{ __('application.percentage_c4519ac7') }}</option>
                                        </select>
                                    </label>
                                    <label class="block min-w-0 text-[11px] font-black uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                        {{ $t(__('application.discount_b524936d')) }} {{ ($item['discount_type'] ?? 'fixed') === 'percentage' ? '(%)' : '(TZS)' }}
                                        <input wire:model.live.debounce.300ms="cart.{{ $index }}.discount_value" type="number" inputmode="decimal" min="0" step="0.01" class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-1 text-sm font-semibold normal-case tracking-normal dark:border-slate-700 dark:bg-navy-950">
                                    </label>
                                </div>
                            @endif
                        </div>

                        <div class="mt-2 grid gap-1 text-xs text-slate-500 dark:text-slate-400">
                            @if ($this->canApplyDiscount() && $discount_mode === 'item')
                                <div class="flex justify-between"><span>{{ __('application.discount_b524936d') }}</span><span>TZS {{ \App\Support\NumberFormatter::money($lineDiscount) }}</span></div>
                            @endif
                            <div class="flex justify-between font-black text-slate-800 dark:text-slate-100"><span>{{ __('application.line_total_2d1a09ef') }}</span><span>TZS {{ \App\Support\NumberFormatter::money(max(0, ($quantity * $unitPrice) - $lineDiscount + ($quantity * $taxPerUnit))) }}</span></div>
                            @if ($isFractionalSale || abs($conversionFactor - 1) > 0.0001)
                                <div class="flex justify-between"><span>{{ $t(__('application.available_stock_f5ba03b5')) }}</span><span>{{ \App\Support\NumberFormatter::quantity($availableSellingQuantity) }} {{ $sellingUnitLabel }}</span></div>
                                @if ($baseUnitLabel && ($baseUnitLabel !== $sellingUnitLabel || abs($conversionFactor - 1) > 0.0001))
                                    <div class="flex justify-between"><span>{{ $t(__('terminology.available_base_stock_2a2c9072')) }}</span><span>{{ \App\Support\NumberFormatter::quantity($availableBaseQuantity) }} {{ $baseUnitLabel }}</span></div>
                                    <div class="flex justify-between"><span>{{ $t(__('terminology.unit_conversion_76a96316')) }}</span><span>{{ $usesDirectConversion ? '1 '.$sellingUnitLabel.' = '.\App\Support\NumberFormatter::quantity($conversionFactor).' '.$baseUnitLabel : '1 '.$baseUnitLabel.' = '.\App\Support\NumberFormatter::quantity($conversionFactor).' '.$sellingUnitLabel }}</span></div>
                                @endif
                                <div class="flex justify-between font-black text-slate-700 dark:text-slate-200"><span>{{ $t(__('application.selling_quantity_a92a7f90')) }}</span><span>{{ \App\Support\NumberFormatter::quantity($quantity) }} {{ $sellingUnitLabel }}</span></div>
                                <div class="flex justify-between font-black text-slate-700 dark:text-slate-200"><span>{{ $t(__('terminology.base_quantity_deducted_af425892')) }}</span><span>{{ \App\Support\NumberFormatter::quantity($baseQuantity) }} {{ $baseUnitLabel }}</span></div>
                            @endif
                        </div>
                        @error("cart.{$index}.quantity")<p class="mt-2 text-xs font-semibold text-red-600">{{ $message }}</p>@enderror
                        @error("cart.{$index}.discount_value")<p class="mt-2 text-xs font-semibold text-red-600">{{ $message }}</p>@enderror
                    </div>
                @endforeach

                @error('cart') <p class="text-sm font-semibold text-red-600">{{ $message }}</p> @enderror

                @if ($this->canApplyDiscount() && $discount_mode === 'order')
                    <div data-order-discount-editor class="rounded-lg border border-orange-200 bg-orange-50 p-3 dark:border-orange-500/30 dark:bg-orange-500/10">
                        <p class="text-xs font-black uppercase tracking-wide text-orange-700 dark:text-orange-200">{{ __('application.total_discount_63987053') }}</p>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            <label class="text-xs font-black uppercase tracking-wide text-slate-600 dark:text-slate-300">{{ __('application.type_3deb7456') }}
                                <select wire:model.live="order_discount_type" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-2 text-sm font-semibold normal-case dark:border-slate-700 dark:bg-navy-950">
                                    <option value="fixed">{{ __('application.fixed_amount_e6dbba2c') }}</option>
                                    <option value="percentage">{{ __('application.percentage_c4519ac7') }}</option>
                                </select>
                            </label>
                            <label class="text-xs font-black uppercase tracking-wide text-slate-600 dark:text-slate-300">{{ __('application.value_8dce170d') }} {{ $order_discount_type === 'percentage' ? '(%)' : '(TZS)' }}
                                <input wire:model.live.debounce.300ms="order_discount_value" type="number" inputmode="decimal" min="0" step="0.01" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-2 text-sm font-semibold normal-case dark:border-slate-700 dark:bg-navy-950">
                            </label>
                            @error('order_discount_value') <p class="text-xs font-semibold text-red-600 sm:col-span-2">{{ $message }}</p> @enderror
                        </div>
                    </div>
                @endif

                <div class="space-y-2 border-t border-slate-200 pt-3 text-sm dark:border-slate-800">
                    <div class="flex justify-between"><span>{{ $t(__('application.subtotal_97f7359e')) }}</span><span>TZS {{ \App\Support\NumberFormatter::money($this->subtotal()) }}</span></div>
                    <div class="flex justify-between"><span>{{ $t(__('application.discount_b524936d')) }}</span><span>TZS {{ \App\Support\NumberFormatter::money($this->discountTotal()) }}</span></div>
                    <div class="flex justify-between"><span>{{ $t(__('application.tax_vat_5b85846e')) }}</span><span>TZS {{ \App\Support\NumberFormatter::money($this->taxTotal()) }}</span></div>
                    <div class="flex justify-between text-lg font-black"><span>{{ $t(__('application.grand_total_91faafb1')) }}</span><span>TZS {{ \App\Support\NumberFormatter::money($this->grandTotal()) }}</span></div>
                    <div class="flex justify-between"><span>{{ $t(__('application.paid_dc9d4584')) }}</span><span>TZS {{ \App\Support\NumberFormatter::money($this->paidTotal()) }}</span></div>
                    @if ($this->paidTotal() < $this->grandTotal())
                        <div class="flex justify-between"><span>{{ $t(__('application.balance_90eef613')) }}</span><span>TZS {{ \App\Support\NumberFormatter::money($this->grandTotal() - $this->paidTotal()) }}</span></div>
                    @endif
                </div>

                <div class="space-y-2">
                    @foreach ($payments as $index => $payment)
                        <div class="grid grid-cols-[1fr_1fr_auto] gap-2">
                            <select wire:model.live="payments.{{ $index }}.payment_method" wire:change="syncDefaultPaymentAmount" class="rounded-lg border border-slate-200 bg-white px-2 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                                <option value="cash">{{ $t(__('application.cash_758ec54e')) }}</option>
                                <option value="mobile_money">{{ $t(__('application.mobile_money_6a38d1e4')) }}</option>
                                <option value="bank">{{ $t(__('application.bank_9e89988c')) }}</option>
                                @if ($this->canCreditSale())
                                    <option value="credit">{{ $t(__('application.credit_8a45d339')) }}</option>
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
                    <button type="button" wire:click="addPayment" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700">{{ $t(__('application.add_payment_b5118402')) }}</button>
                </div>

                @error('sale') <p class="text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
                <button
                    type="button"
                    wire:click="completeSale"
                    wire:loading.attr="disabled"
                    wire:target="completeSale,continueWithoutCustomer,cart,payments"
                    @disabled($processing)
                    class="w-full rounded-xl bg-build-orange px-4 py-3 font-black text-white shadow-lg shadow-orange-500/20 disabled:cursor-wait disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="completeSale,continueWithoutCustomer">{{ $t(__('application.complete_sale_ebdd2780')) }}</span>
                    <span wire:loading wire:target="completeSale,continueWithoutCustomer">{{ __('application.processing_272bc02e') }}</span>
                </button>
            </div>
        </x-card>
    </div>

    <div class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 p-3 shadow-2xl backdrop-blur dark:border-slate-800 dark:bg-slate-950/95 xl:hidden">
        <button
            type="button"
            wire:click="completeSale"
            wire:loading.attr="disabled"
            wire:target="completeSale,continueWithoutCustomer,cart,payments"
            @disabled($processing)
            class="flex w-full items-center justify-between rounded-xl bg-build-orange px-4 py-3 font-black text-white disabled:cursor-wait disabled:opacity-60"
        >
            <span>{{ $t(__('application.checkout_3ac8e9e5')) }}</span>
            <span>TZS {{ \App\Support\NumberFormatter::money($this->grandTotal()) }}</span>
        </button>
    </div>

    <x-modal name="unassigned-credit-warning" maxWidth="2xl">
        <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
            <h2 class="text-lg font-black text-slate-900 dark:text-white">{{ $t(__('terminology.customer_not_selected_76bdea35')) }}</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $t(__('terminology.this_sale_will_be_recorded_as_unassigned_credit_it_may_be_assigned_to_the_c_fa2e28d8')) }}</p>
        </div>
        <div class="px-5 py-5">
            <div class="grid gap-3 sm:grid-cols-2">
                <x-form-input label="{{ __('application.customer_name_75636316') }}" name="temporary_customer_name" wire:model="temporary_customer_name" />
                <x-form-input label="{{ __('application.phone_number_ab25d61b') }}" name="temporary_customer_phone" wire:model="temporary_customer_phone" />
                <x-form-input label="{{ __('application.site_or_project_name_91621028') }}" name="project_name" wire:model="project_name" />
                <x-form-input label="{{ __('application.vehicle_number_8dc10331') }}" name="vehicle_number" wire:model="vehicle_number" />
                <x-form-input label="{{ __('application.expected_payment_date_ca24c671') }}" name="expected_payment_date" type="date" wire:model="expected_payment_date" />
                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200 sm:col-span-2">
                    {{ $t(__('application.notes_70440046')) }}
                    <textarea wire:model="credit_notes" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
                    @error('credit_notes') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
            </div>
            <div class="mt-5 flex flex-wrap justify-end gap-2">
                <button type="button" wire:click="selectCustomerFromWarning" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ $t(__('terminology.select_customer_80b02e00')) }}</button>
                <button type="button" wire:click="quickAddCustomerFromWarning" class="rounded-xl border border-cyan-200 bg-cyan-50 px-4 py-2.5 text-sm font-black text-cyan-700 dark:border-cyan-500/30 dark:bg-cyan-500/10 dark:text-cyan-100">{{ $t(__('terminology.quick_add_customer_1c08ed8c')) }}</button>
                <button type="button" wire:click="continueWithoutCustomer" class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white shadow-lg shadow-orange-500/25">{{ $t(__('terminology.continue_without_customer_83c2ee0e')) }}</button>
                <button type="button" wire:click="cancelUnassignedCreditWarning" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ $t(__('application.cancel_77dfd213')) }}</button>
            </div>
        </div>
    </x-modal>

    <x-modal name="quick-customer" maxWidth="3xl">
        <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
            <div>
                <h2 class="text-lg font-black text-slate-900 dark:text-white">{{ $t(__('application.create_customer_2bd83aec')) }}</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $t(__('terminology.add_a_credit_customer_without_leaving_pos_55797940')) }}</p>
            </div>
            <button type="button" x-on:click="$dispatch('close-modal', 'quick-customer')" class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-black dark:border-slate-700">X</button>
        </div>

        <form wire:submit="saveQuickCustomer" class="max-h-[calc(100vh-9rem)] overflow-y-auto px-5 py-5">
            <div class="grid gap-4 md:grid-cols-2">
                <x-form-input label="{{ __('application.customer_name_75636316') }}" name="quick_customer_name" wire:model="quick_customer_name" required />
                <x-form-input label="{{ __('application.phone_77064d52') }}" name="quick_customer_phone" wire:model="quick_customer_phone" />
                <x-form-input label="{{ __('application.email_84add5b2') }}" name="quick_customer_email" type="email" wire:model="quick_customer_email" />

                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                    {{ $t(__('application.customer_type_9f8a0a78')) }}
                    <select wire:model="quick_customer_type" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        <option value="cash">{{ $t(__('application.cash_758ec54e')) }}</option>
                        <option value="credit">{{ $t(__('application.credit_8a45d339')) }} / {{ $t(__('terminology.credit_8a45d339')) }}</option>
                        <option value="contractor">{{ $t(__('application.contractor_76bb2328')) }}</option>
                        <option value="wholesale">{{ $t(__('application.wholesale_21c1aef4')) }}</option>
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

                <x-money-input label="{{ __('application.opening_balance_1a1a6305') }}" name="quick_customer_opening_balance" wire:model="quick_customer_opening_balance" required />

                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                    {{ $t(__('application.branch_1627510b')) }}
                    <select wire:model="quick_customer_branch_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())>
                        <option value="">{{ $t(__('application.global_customer_20d38e15')) }}</option>
                        @foreach (Branch::orderBy('name')->get() as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                    @error('quick_customer_branch_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                    {{ $t(__('application.status_bae7d5be')) }}
                    <select wire:model="quick_customer_status" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        <option value="active">{{ $t(__('application.active_a733b809')) }}</option>
                        <option value="inactive">{{ $t(__('application.inactive_09af574c')) }}</option>
                    </select>
                    @error('quick_customer_status') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200 md:col-span-2">
                    {{ $t(__('application.address_d70f93df')) }}
                    <textarea wire:model="quick_customer_address" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
                    @error('quick_customer_address') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
            </div>

            <div class="sticky bottom-0 -mx-5 mt-5 flex justify-end gap-2 border-t border-slate-200 bg-white px-5 py-4 dark:border-slate-800 dark:bg-slate-900">
                <button type="button" x-on:click="$dispatch('close-modal', 'quick-customer')" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ $t(__('application.cancel_77dfd213')) }}</button>
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white shadow-lg shadow-orange-500/25">{{ $t(__('application.save_customer_ffe9f842')) }}</button>
            </div>
        </form>
    </x-modal>
</div>
