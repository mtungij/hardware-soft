<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseCostType;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\ProductUnitConversionService;
use App\Services\PurchaseCostBreakdownService;
use App\Support\CompanyFeatures;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['purchase' => null, 'branch_id' => '', 'supplier_id' => '', 'purchase_date' => '', 'invoice_number' => '', 'reference_number' => '', 'notes' => '', 'paid_amount' => '0', 'items' => [], 'breakdown_open' => [], 'breakdown_complete' => [], 'new_cost_type' => '']);

mount(function (Purchase $purchase) {
    abort_unless($purchase->canBeModified(), 403);

    app(PurchaseCostBreakdownService::class)->ensureDefaultTypes((int) $purchase->company_id);
    $this->purchase = $purchase->load('items.costBreakdown');
    $this->branch_id = (string) $purchase->branch_id;
    $this->supplier_id = (string) $purchase->supplier_id;
    $this->purchase_date = $purchase->purchase_date->toDateString();
    $this->invoice_number = $purchase->invoice_number;
    $this->reference_number = $purchase->reference_number;
    $this->notes = $purchase->notes;
    $this->paid_amount = (string) $purchase->paid_amount;
    $this->items = $purchase->items->map(fn ($item) => [
        'id' => $item->id,
        'product_id' => (string) $item->product_id,
        'product_unit_conversion_id' => $item->product_unit_conversion_id ? (string) $item->product_unit_conversion_id : '',
        'use_base_unit' => ! $item->product_unit_conversion_id && (int) $item->purchase_unit_id === (int) $item->stock_unit_id,
        'purchase_unit_id' => (string) $item->purchase_unit_id,
        'purchase_conversion_factor' => (float) $item->purchaseFactor(),
        'ordered_quantity' => (string) $item->ordered_quantity,
        'cost_price' => (string) $item->cost_price,
        'selling_price' => (string) $item->selling_price,
        'cost_breakdown' => $item->costBreakdown->map(fn ($row) => ['type_id' => (string) $row->purchase_cost_type_id, 'amount' => (string) $row->amount, 'reference' => $row->reference ?? '', 'notes' => $row->notes ?? ''])->all(),
    ])->all();
});

$toggleCostBreakdown = function (int $index): void {
    if ((float) ($this->items[$index]['cost_price'] ?? 0) <= 0) {
        return;
    }

    $this->breakdown_open[$index] = ! ($this->breakdown_open[$index] ?? false);
};

$addCostType = function (): void {
    abort_unless(auth()->user()?->hasAnyRole(['Super Admin', 'Admin']), 403);
    $this->validate(['new_cost_type' => ['required', 'string', 'max:100']]);
    $type = PurchaseCostType::query()->firstOrCreate(
        ['company_id' => auth()->user()->company_id, 'name' => trim($this->new_cost_type)],
        ['is_active' => true],
    );
    $type->update(['is_active' => true]);
    $this->new_cost_type = '';
    $this->dispatch('close-modal', 'purchase-cost-types');
};

$addCostComponent = function (int $index): void {
    $this->items[$index]['cost_breakdown'][] = ['type_id' => '', 'amount' => '', 'reference' => '', 'notes' => ''];
    $this->breakdown_complete[$index] = false;
    $this->breakdown_open[$index] = true;
};

$removeCostComponent = function (int $index, int $row): void {
    unset($this->items[$index]['cost_breakdown'][$row]);
    $this->items[$index]['cost_breakdown'] = array_values($this->items[$index]['cost_breakdown']);
    $this->breakdown_complete[$index] = false;
    $this->breakdown_open[$index] = true;
};

$breakdownTotal = function (int $index): float {
    return round(collect($this->items[$index]['cost_breakdown'] ?? [])->sum(fn ($row) => is_numeric($row['amount'] ?? null) ? (float) $row['amount'] : 0), 2);
};

$breakdownRemaining = function (int $index): float {
    return round((float) ($this->items[$index]['cost_price'] ?? 0) - $this->breakdownTotal($index), 2);
};

$breakdownIsComplete = function (int $index): bool {
    return ($this->breakdown_complete[$index] ?? false)
        && ! empty($this->items[$index]['cost_breakdown'])
        && abs($this->breakdownRemaining($index)) < 0.005;
};

$hasIncompleteBreakdown = function (): bool {
    foreach ($this->items as $index => $item) {
        if (! empty($item['cost_breakdown']) && abs($this->breakdownRemaining($index)) >= 0.005) {
            return true;
        }
    }

    return false;
};

$completeCostBreakdown = function (int $index): void {
    $item = $this->items[$index] ?? null;
    if (! $item || empty($item['cost_breakdown']) || (float) ($item['cost_price'] ?? 0) <= 0
        || abs($this->breakdownRemaining($index)) >= 0.005) {
        return;
    }

    app(PurchaseCostBreakdownService::class)->prepare(
        (int) auth()->user()->company_id,
        $item['cost_breakdown'],
        "items.{$index}.cost_breakdown",
    );
    $this->breakdown_complete[$index] = true;
    $this->breakdown_open[$index] = false;
};

$addItem = function () {
    if (blank($this->supplier_id)) {
        $this->addError('supplier_id', 'Select supplier before adding products.');

        return;
    }

    $this->items[] = ['id' => null, 'product_id' => '', 'product_unit_conversion_id' => '', 'use_base_unit' => false,
        'purchase_unit_id' => '', 'purchase_conversion_factor' => 1, 'ordered_quantity' => '1', 'cost_price' => '0', 'selling_price' => '', 'cost_breakdown' => []];
};

$removeItem = function (int $index) {
    unset($this->items[$index]);
    $this->items = array_values($this->items);
    $this->breakdown_open = [];
    $this->breakdown_complete = [];
};

$syncProductSellingPrice = function (int $index) {
    $productId = $this->items[$index]['product_id'] ?? null;
    $product = $productId ? Product::query()->with(['unit', 'purchaseUnit', 'unitConversions.unit'])->find($productId) : null;
    $conversion = $product?->unitConversions->first(fn ($row) => $row->active && $row->can_purchase && $row->unit?->status === 'active'
        && (int) $row->unit_id === (int) $product->purchase_unit_id);
    $usesBase = $product && (! $product->purchase_unit_id || (int) $product->purchase_unit_id === (int) $product->unit_id);
    $factor = $conversion ? (float) $conversion->conversion_factor : ($usesBase ? 1 : ($product?->purchaseConversionFactor() ?? 1));

    $this->items[$index]['product_unit_conversion_id'] = $conversion ? (string) $conversion->id : '';
    $this->items[$index]['use_base_unit'] = (bool) $usesBase;
    $this->items[$index]['purchase_unit_id'] = $conversion?->unit_id ?: ($product?->purchase_unit_id ?: $product?->unit_id ?: '');
    $this->items[$index]['purchase_conversion_factor'] = $factor;
    $this->items[$index]['cost_price'] = (string) ($conversion?->purchase_price ?? ((float) ($product?->buying_price ?? 0) * $factor));
    $this->items[$index]['cost_breakdown'] = [];
    $this->breakdown_complete[$index] = false;
    $this->items[$index]['selling_price'] = $product ? (string) $product->selling_price : '';
};

$selectPurchaseUnit = function (int $index, string $selection): void {
    $product = Product::query()->with(['unit', 'purchaseUnit'])->find($this->items[$index]['product_id'] ?? null);
    if (! $product) {
        return;
    }

    if ($selection === 'base') {
        $conversion = null;
        $unitId = $product->unit_id;
        $factor = 1;
        $usesBase = true;
    } elseif ($selection === 'configured' && $product->purchase_unit_id
        && (int) $product->purchase_unit_id !== (int) $product->unit_id) {
        $conversion = null;
        $unitId = $product->purchase_unit_id;
        $factor = $product->purchaseConversionFactor();
        $usesBase = false;
    } else {
        $conversion = app(ProductUnitConversionService::class)->resolveForPurchase($product, (int) $selection);
        $unitId = $conversion->unit_id;
        $factor = (float) $conversion->conversion_factor;
        $usesBase = false;
    }

    $this->items[$index]['product_unit_conversion_id'] = $conversion ? (string) $conversion->id : '';
    $this->items[$index]['use_base_unit'] = $usesBase;
    $this->items[$index]['purchase_unit_id'] = $unitId;
    $this->items[$index]['purchase_conversion_factor'] = $factor;
    $this->items[$index]['cost_price'] = (string) ($conversion?->purchase_price ?? ((float) $product->buying_price * $factor));
    $this->items[$index]['cost_breakdown'] = [];
    $this->breakdown_complete[$index] = false;
};

$updatedItems = function (mixed $value = null, ?string $key = null): void {
    if ($key !== null && preg_match('/^(\d+)\.(cost_price|cost_breakdown)(\.|$)/', $key, $matches)) {
        $this->breakdown_complete[(int) $matches[1]] = false;
    }
};

$totalAmount = function () {
    return collect($this->items)->sum(fn ($item) => (float) ($item['ordered_quantity'] ?? 0) * (float) ($item['cost_price'] ?? 0));
};

$savePurchase = function (string $status) {
    abort_unless($this->purchase->canBeModified(), 403);

    $historicalProductIds = $this->purchase->items()->pluck('product_id')->map(fn ($id) => (int) $id)->all();

    $validated = $this->validate([
        'branch_id' => ['required', 'exists:branches,id'],
        'supplier_id' => ['required', 'exists:suppliers,id'],
        'purchase_date' => ['required', 'date'],
        'invoice_number' => ['nullable', 'string', 'max:255'],
        'reference_number' => ['required', 'string', 'max:255', Rule::unique('purchases', 'reference_number')->ignore($this->purchase->id)],
        'notes' => ['nullable', 'string', 'max:1000'],
        'paid_amount' => ['required', 'numeric', 'min:0'],
        'items' => ['required', 'array', 'min:1'],
        'items.*.product_id' => [
            'required',
            Rule::exists('products', 'id')->where(function ($query) use ($historicalProductIds): void {
                $query->where('company_id', auth()->user()->company_id);

                if (CompanyFeatures::manufacturingEnabled()) {
                    $query->where(function ($products) use ($historicalProductIds): void {
                        $products
                            ->where('inventory_source', Product::INVENTORY_SOURCE_PURCHASED)
                            ->orWhereIn('id', $historicalProductIds);
                    });
                }
            }),
        ],
        'items.*.product_unit_conversion_id' => ['nullable', 'integer'],
        'items.*.use_base_unit' => ['boolean'],
        'items.*.ordered_quantity' => ['required', 'numeric', 'gt:0'],
        'items.*.cost_price' => ['required', 'numeric', 'min:0'],
        'items.*.selling_price' => ['nullable', 'numeric', 'min:0'],
        'items.*.cost_breakdown' => ['nullable', 'array'],
        'items.*.cost_breakdown.*.type_id' => ['nullable'],
        'items.*.cost_breakdown.*.amount' => ['nullable'],
        'items.*.cost_breakdown.*.reference' => ['nullable', 'string', 'max:255'],
        'items.*.cost_breakdown.*.notes' => ['nullable', 'string', 'max:1000'],
    ]);

    foreach ($validated['items'] as $index => $item) {
        $product = Product::query()->with(['unit'])->findOrFail($item['product_id']);
        $quantity = (float) $item['ordered_quantity'];
        $selectedUnit = Unit::query()->with('measurementType')->find($this->items[$index]['purchase_unit_id'] ?? null);
        $factor = (float) ($this->items[$index]['purchase_conversion_factor'] ?? 1);

        if (($selectedUnit?->measurementType?->code === \App\Models\MeasurementType::COUNT && ! $product->quantityIsWhole($quantity))
            || ! $product->acceptsStockQuantity(round($quantity * $factor, 4))) {
            throw ValidationException::withMessages([
                "items.{$index}.ordered_quantity" => 'Enter a quantity valid for '.($selectedUnit?->short_name ?: $product->unit?->short_name).' and the base stock unit.',
            ]);
        }
    }

    $total = $this->totalAmount();

    if ((float) $validated['paid_amount'] > $total) {
        throw ValidationException::withMessages(['paid_amount' => 'Paid amount cannot exceed total amount.']);
    }

    $breakdowns = [];
    foreach ($validated['items'] as $index => $line) {
        $breakdowns[$index] = app(PurchaseCostBreakdownService::class)->prepare(
            (int) $this->purchase->company_id,
            $line['cost_breakdown'] ?? [],
            "items.{$index}.cost_breakdown",
        )['rows'];
    }

    DB::transaction(function () use ($validated, $status, $total, $breakdowns) {
        $paid = (float) $validated['paid_amount'];
        $this->purchase->update([
            'branch_id' => $validated['branch_id'],
            'supplier_id' => $validated['supplier_id'],
            'purchase_date' => $validated['purchase_date'],
            'invoice_number' => $validated['invoice_number'],
            'reference_number' => $validated['reference_number'],
            'status' => $status,
            'payment_status' => ($total - $paid) <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
            'total_amount' => $total,
            'paid_amount' => $paid,
            'balance_amount' => max(0, $total - $paid),
            'notes' => $validated['notes'],
        ]);

        $originalItems = $this->purchase->items->keyBy('id');
        $this->purchase->items()->delete();

        foreach ($validated['items'] as $index => $item) {
            $product = Product::query()->with(['unit', 'purchaseUnit'])->findOrFail($item['product_id']);
            $quantity = (float) $item['ordered_quantity'];
            $cost = (float) $item['cost_price'];
            $selectedConversionId = filled($item['product_unit_conversion_id'] ?? null)
                ? (int) $item['product_unit_conversion_id'] : null;
            $original = $originalItems->get($this->items[$index]['id'] ?? null);
            $preserveSnapshot = $original && (int) $original->product_id === (int) $product->id
                && (int) $original->product_unit_conversion_id === (int) $selectedConversionId
                && (int) $original->purchase_unit_id === (int) ($this->items[$index]['purchase_unit_id'] ?? 0);

            if ($preserveSnapshot) {
                $purchaseUnitId = $original->purchase_unit_id;
                $stockUnitId = $original->stock_unit_id;
                $factor = $original->purchaseFactor();
                $purchaseUnitName = $original->purchase_unit_name_snapshot ?: $original->purchaseUnit?->name;
                $purchaseUnitCode = $original->purchase_unit_code_snapshot ?: $original->purchaseUnit?->short_name;
                $stockUnitName = $original->stock_unit_name_snapshot ?: $original->stockUnit?->name;
                $stockUnitCode = $original->stock_unit_code_snapshot ?: $original->stockUnit?->short_name;
            } else {
                $conversion = app(ProductUnitConversionService::class)->resolveForPurchase($product, $selectedConversionId);
                $usesBase = (bool) ($item['use_base_unit'] ?? false);
                $purchaseUnit = $conversion?->unit ?: ($usesBase ? $product->unit : $product->purchaseUnit);
                $purchaseUnitId = $purchaseUnit?->id ?: $product->unit_id;
                $stockUnitId = $product->unit_id;
                $factor = $conversion ? (float) $conversion->conversion_factor : ($usesBase ? 1 : $product->purchaseConversionFactor());
                $purchaseUnitName = $purchaseUnit?->name;
                $purchaseUnitCode = $purchaseUnit?->short_name;
                $stockUnitName = $product->unit?->name;
                $stockUnitCode = $product->unit?->short_name;
            }

            $transactionUnit = Unit::query()->with('measurementType')->find($purchaseUnitId);
            if (($transactionUnit?->measurementType?->code === \App\Models\MeasurementType::COUNT && ! $product->quantityIsWhole($quantity))
                || ! $product->acceptsStockQuantity(round($quantity * $factor, 4))) {
                throw ValidationException::withMessages([
                    "items.{$index}.ordered_quantity" => 'Enter a quantity valid for the selected unit and the base stock unit.',
                ]);
            }

            $purchaseItem = $this->purchase->items()->create([
                'product_id' => $product->id,
                'product_unit_conversion_id' => $selectedConversionId,
                'purchase_unit_id' => $purchaseUnitId,
                'stock_unit_id' => $stockUnitId,
                'purchase_conversion_factor' => $factor,
                'purchase_unit_name_snapshot' => $purchaseUnitName,
                'purchase_unit_code_snapshot' => $purchaseUnitCode,
                'stock_unit_name_snapshot' => $stockUnitName,
                'stock_unit_code_snapshot' => $stockUnitCode,
                'product_size_id' => $product->product_size_id,
                'ordered_quantity' => $quantity,
                'base_ordered_quantity' => round($quantity * $factor, 4),
                'received_quantity' => 0,
                'base_received_quantity' => 0,
                'cost_price' => $cost,
                'selling_price' => $item['selling_price'] ?: null,
                'line_total' => $quantity * $cost,
            ]);
            app(PurchaseCostBreakdownService::class)->save($purchaseItem, $breakdowns[$index]);
        }
    });

    session()->flash('success', 'Purchase updated successfully.');
    $this->redirectRoute('purchases.index', navigate: true);
};

?>

<div>
    <x-page-header title="Edit Purchase" description="Only purchases with no received stock can be edited." :breadcrumbs="['Dashboard' => route('dashboard'), 'Purchases' => route('purchases.index'), 'Edit' => null]" />

    @include('livewire.purchases.partials.cost-type-manager')

    @include('livewire.purchases.partials.form-fields')
</div>
