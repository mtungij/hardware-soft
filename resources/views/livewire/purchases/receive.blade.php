<?php

use App\Models\ProductLocationSetting;
use App\Models\Purchase;
use App\Models\PurchaseCostType;
use App\Services\PurchaseCostBreakdownService;
use App\Models\StockLocation;
use App\Services\InventoryService;
use App\Support\InventorySettings;
use Illuminate\Validation\Rule;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'purchase' => null,
    'purchase_id' => null,
    'grn_number' => '',
    'received_date' => '',
    'supplier_delivery_note_number' => '',
    'supplier_invoice_number' => '',
    'default_stock_location_id' => '',
    'notes' => '',
    'lines' => [],
    'additional_costs' => [],
    'new_cost_type' => '',
]);

mount(function (Purchase $purchase, InventoryService $inventory) {
    abort_if($purchase->status === 'cancelled' || $purchase->status === 'received', 403);

    app(PurchaseCostBreakdownService::class)->ensureDefaultTypes((int) $purchase->company_id);
    $this->purchase = $purchase->load(['supplier', 'branch', 'creator', 'items.product', 'items.purchaseUnit.measurementType', 'items.stockUnit']);
    $this->purchase_id = $purchase->id;
    if (blank($this->grn_number)) {
        $this->grn_number = $inventory->generateGrnNumber((int) $purchase->company_id);
    }
    $this->received_date = now()->toDateString();

    $defaultLocation = $this->availableReceivingLocations()->first()
        ?: InventorySettings::receivingLocation((int) $purchase->branch_id);
    $this->default_stock_location_id = (string) $defaultLocation->id;

    foreach ($this->purchase->items as $item) {
        $preferred = ProductLocationSetting::query()
            ->where('product_id', $item->product_id)
            ->where(fn ($query) => $query->where('branch_id', $this->purchase->branch_id)->orWhereNull('branch_id'))
            ->whereNotNull('preferred_receiving_location_id')
            ->orderByDesc('branch_id')
            ->value('preferred_receiving_location_id');

        $locationId = $preferred ?: $this->default_stock_location_id;

        if (! $this->availableReceivingLocations()->contains('id', (int) $locationId)) {
            $locationId = $this->default_stock_location_id;
        }

        $this->lines[$item->id] = [
            'quantity' => '0',
            'stock_location_id' => (string) $locationId,
            'batch_number' => '',
            'expiry_date' => '',
            'notes' => '',
        ];
    }
});

$availableReceivingLocations = function () {
    $user = auth()->user();
    $branchId = (int) ($this->purchase?->branch_id ?: Purchase::find($this->purchase_id)?->branch_id);
    $locations = $user?->permittedStockLocations('can_receive', $branchId)
        ->filter(fn (StockLocation $location) => $location->isActive() && $location->can_receive_stock)
        ->values() ?? collect();

    if ($locations->isNotEmpty()) {
        return $locations;
    }

    if ($user?->stockLocations()->exists()) {
        return collect();
    }

    if ($user?->hasAnyRole(['Super Admin', 'Admin', 'Manager', 'Store Keeper'])) {
        return StockLocation::query()
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->where('is_active', true)
            ->where('can_receive_stock', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    return collect();
};

$updatedDefaultStockLocationId = function ($value) {
    foreach ($this->lines as $itemId => $line) {
        $this->lines[$itemId]['stock_location_id'] = (string) $value;
    }
};

$addCostType = function (): void {
    abort_unless(auth()->user()?->hasAnyRole(['Super Admin', 'Admin']), 403);
    $this->validate(['new_cost_type' => ['required', 'string', 'max:100']]);
    $name = trim($this->new_cost_type);
    if (mb_strtolower($name) === 'product cost') {
        $this->addError('new_cost_type', 'Product Cost is already included in goods value.');

        return;
    }
    $type = PurchaseCostType::query()->firstOrCreate(
        ['company_id' => auth()->user()->company_id, 'name' => $name],
        ['is_active' => true],
    );
    $type->update(['is_active' => true]);
    $this->new_cost_type = '';
};

$addAdditionalCost = function (): void {
    $this->additional_costs[] = ['type_id' => '', 'amount' => '', 'payee' => '', 'payment_method' => '', 'payment_reference' => '', 'notes' => ''];
};

$removeAdditionalCost = function (int $index): void {
    unset($this->additional_costs[$index]);
    $this->additional_costs = array_values($this->additional_costs);
};

$receiveAll = function () {
    $purchase = Purchase::query()->with('items')->findOrFail($this->purchase_id);

    foreach ($purchase->items as $item) {
        $this->lines[$item->id]['quantity'] = (string) $item->remainingQuantity();
    }
};

$summary = function (): array {
    $purchase = Purchase::query()->with('items')->findOrFail($this->purchase_id);
    $selectedLines = 0;
    $quantity = 0;
    $cost = 0;
    $remainingAfter = 0;
    $locations = collect();

    foreach ($purchase->items as $item) {
        $line = $this->lines[$item->id] ?? [];
        $lineQuantity = (float) ($line['quantity'] ?? 0);
        $remainingAfter += max(0, $item->remainingQuantity() - $lineQuantity);

        if ($lineQuantity <= 0) {
            continue;
        }

        $selectedLines++;
        $quantity += $lineQuantity;
        $cost += $lineQuantity * (float) $item->cost_price;
        $locations->push((int) ($line['stock_location_id'] ?? 0));
    }

    $additional = collect($this->additional_costs)->sum(fn ($row) => is_numeric($row['amount'] ?? null) ? (float) $row['amount'] : 0);

    return [
        'additional' => $additional,
        'landed' => $cost + $additional,
        'selected_lines' => $selectedLines,
        'quantity' => $quantity,
        'cost' => $cost,
        'locations' => $locations->filter()->unique()->count(),
        'remaining_after' => $remainingAfter,
    ];
};

$locationBreakdown = function () {
    $locations = $this->availableReceivingLocations()->keyBy('id');

    return collect($this->lines)
        ->filter(fn ($line) => (float) ($line['quantity'] ?? 0) > 0)
        ->groupBy(fn ($line) => (int) ($line['stock_location_id'] ?? 0))
        ->map(fn ($rows, $locationId) => [
            'name' => $locations->get((int) $locationId)?->name ?? 'Unknown',
            'quantity' => $rows->sum(fn ($line) => (float) ($line['quantity'] ?? 0)),
        ])
        ->values();
};

$openConfirmation = function () {
    $this->validateReceiving();
    $this->dispatch('open-modal', 'confirm-receiving');
};

$validateReceiving = function () {
    $locationIds = $this->availableReceivingLocations()->pluck('id')->map(fn ($id) => (string) $id)->all();

    $rules = [
        'grn_number' => ['required', 'string', 'max:255'],
        'received_date' => ['required', 'date'],
        'supplier_delivery_note_number' => ['nullable', 'string', 'max:255'],
        'supplier_invoice_number' => ['nullable', 'string', 'max:255'],
        'default_stock_location_id' => ['required', Rule::in($locationIds)],
        'notes' => ['nullable', 'string', 'max:1000'],
        'lines' => ['required', 'array'],
        'additional_costs' => ['array'],
        'additional_costs.*.type_id' => ['required', Rule::exists('purchase_cost_types', 'id')->where('company_id', auth()->user()->company_id)->where('is_active', true)->where('name', '!=', 'Product Cost')],
        'additional_costs.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
        'additional_costs.*.payee' => ['nullable', 'string', 'max:255'],
        'additional_costs.*.payment_method' => ['nullable', 'string', 'max:100'],
        'additional_costs.*.payment_reference' => ['nullable', 'string', 'max:255'],
        'additional_costs.*.notes' => ['nullable', 'string', 'max:1000'],
        'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
        'lines.*.stock_location_id' => ['required', Rule::in($locationIds)],
        'lines.*.notes' => ['nullable', 'string', 'max:1000'],
    ];

    $purchase = Purchase::query()->with('items.product')->findOrFail($this->purchase_id);

    foreach ($purchase->items as $item) {
        $isReceivingLine = (float) ($this->lines[$item->id]['quantity'] ?? 0) > 0;
        $rules["lines.{$item->id}.batch_number"] = $item->product?->tracks_batch && $isReceivingLine
            ? ['required', 'string', 'max:255']
            : (($item->product?->tracks_expiry)
                ? ['nullable', 'string', 'max:255']
                : ['nullable']);
        $rules["lines.{$item->id}.expiry_date"] = $item->product?->tracks_expiry && $isReceivingLine
            ? ['required', 'date', 'after_or_equal:received_date']
            : (($item->product?->tracks_expiry)
                ? ['nullable', 'date', 'after_or_equal:received_date']
                : ['nullable']);
    }

    $this->validate($rules, [
        'lines.*.batch_number.required' => 'Batch Number is required for this product.',
        'lines.*.expiry_date.required' => 'Expiry Date is required for this product.',
        'lines.*.expiry_date.after_or_equal' => 'Expiry Date cannot be earlier than the receiving date.',
    ]);

    $hasQuantity = false;

    foreach ($purchase->items as $item) {
        $quantity = (float) ($this->lines[$item->id]['quantity'] ?? 0);

        if ($quantity <= 0) {
            continue;
        }

        $hasQuantity = true;

        if ($quantity > $item->remainingQuantity()) {
            $this->addError("lines.{$item->id}.quantity", 'Quantity cannot exceed remaining quantity.');
        }
    }

    if (! $hasQuantity) {
        $this->addError('lines', 'Enter at least one quantity to receive.');
    }

    if ($this->getErrorBag()->any()) {
        throw \Illuminate\Validation\ValidationException::withMessages($this->getErrorBag()->toArray());
    }
};

$saveDraft = function (InventoryService $inventory) {
    $this->validateReceiving();

    $purchase = Purchase::query()->findOrFail($this->purchase_id);
    $inventory->receivePurchase($purchase, $this->lines, $this->received_date, auth()->id(), $this->notes, [
        'grn_number' => $this->grn_number,
        'grn_is_system_generated' => true,
        'default_stock_location_id' => (int) $this->default_stock_location_id,
        'supplier_delivery_note_number' => $this->supplier_delivery_note_number ?: null,
        'supplier_invoice_number' => $this->supplier_invoice_number ?: null,
        'status' => 'draft',
        'additional_costs' => $this->additional_costs,
    ]);

    session()->flash('success', 'Goods receipt draft saved.');
    $this->redirectRoute('purchases.show', ['purchase' => $purchase->id], navigate: true);
};

$postReceipt = function (InventoryService $inventory) {
    $this->validateReceiving();

    $purchase = Purchase::query()->findOrFail($this->purchase_id);
    $inventory->receivePurchase($purchase, $this->lines, $this->received_date, auth()->id(), $this->notes, [
        'grn_number' => $this->grn_number,
        'grn_is_system_generated' => true,
        'default_stock_location_id' => (int) $this->default_stock_location_id,
        'supplier_delivery_note_number' => $this->supplier_delivery_note_number ?: null,
        'supplier_invoice_number' => $this->supplier_invoice_number ?: null,
        'status' => 'posted',
        'additional_costs' => $this->additional_costs,
    ]);

    session()->flash('success', 'Purchase received successfully.');
    $this->redirectRoute('purchases.show', ['purchase' => $purchase->id], navigate: true);
};

?>

<div>
    @php
        $locations = $this->availableReceivingLocations();
        $costTypes = PurchaseCostType::query()->where('is_active', true)->where('name', '!=', 'Product Cost')->orderBy('name')->get();
        $locationOptions = $locations->keyBy('id');
        $summary = $this->summary();
        $totalOrdered = $purchase->items->sum('ordered_quantity');
        $totalReceived = $purchase->items->sum('received_quantity');
        $totalRemaining = $purchase->items->sum(fn ($item) => $item->remainingQuantity());
        $showBatchColumn = $purchase->items->contains(fn ($item) => (bool) ($item->product?->tracks_batch || $item->product?->tracks_expiry));
        $showExpiryColumn = $purchase->items->contains(fn ($item) => (bool) $item->product?->tracks_expiry);
    @endphp

    <x-page-header title="Receive Purchase Order" description="Pokea bidhaa za manunuzi kwenye eneo sahihi la stock." :breadcrumbs="['Dashboard' => route('dashboard'), 'Purchases' => route('purchases.index'), 'Receive' => null]">
        <button type="button" wire:click="receiveAll" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Receive All</button>
    </x-page-header>

    <div class="grid gap-4 lg:grid-cols-4">
        @foreach ([
            'Purchase Number' => $purchase->reference_number,
            'Supplier' => $purchase->supplier?->name,
            'Branch' => $purchase->branch?->name,
            'Purchase Date' => $purchase->purchase_date?->format('d M Y'),
            'Ordered By' => $purchase->creator?->name,
            'Purchase Status' => ucfirst($purchase->status),
            'Total Ordered Quantity' => \App\Support\NumberFormatter::quantity($totalOrdered),
            'Previously Received Quantity' => \App\Support\NumberFormatter::quantity($totalReceived),
            'Remaining Quantity' => \App\Support\NumberFormatter::quantity($totalRemaining),
        ] as $label => $value)
            <x-card>
                <p class="text-xs font-bold uppercase text-slate-500">{{ $label }}</p>
                <p class="mt-2 text-base font-black text-navy-900 dark:text-white">{{ $value ?: '-' }}</p>
            </x-card>
        @endforeach
    </div>

    <x-card class="mt-6">
        <form class="space-y-5">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div>
                    <x-form-input label="Goods Receipt Number" name="grn_number" wire:model="grn_number" readonly required />
                    <p class="mt-1 text-xs font-semibold text-slate-500">Generated automatically</p>
                </div>
                <x-form-input label="Receiving Date" name="received_date" type="date" wire:model="received_date" required />
                <x-form-input label="Supplier Delivery Note Number" name="supplier_delivery_note_number" wire:model="supplier_delivery_note_number" />
                <x-form-input label="Supplier Invoice Number" name="supplier_invoice_number" wire:model="supplier_invoice_number" />
                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200 xl:col-span-2">Receive All Into
                    <select wire:model.live="default_stock_location_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}">{{ InventorySettings::stockLocationLabel($location) }}</option>
                        @endforeach
                    </select>
                    @error('default_stock_location_id') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200 xl:col-span-2">Notes
                    <textarea wire:model="notes" class="mt-1 block min-h-20 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
                </label>
            </div>

            @error('lines') <p class="text-sm font-semibold text-red-600">{{ $message }}</p> @enderror

            <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-700">
                <table class="{{ $showBatchColumn || $showExpiryColumn ? 'min-w-[1320px]' : 'min-w-[1040px]' }} w-full text-sm">
                    <thead class="sticky top-0 z-10 bg-slate-100 text-left text-xs uppercase text-slate-500 dark:bg-slate-800">
                        <tr>
                            <th class="px-3 py-3">Product</th>
                            <th class="px-3 py-3">SKU</th>
                            <th class="px-3 py-3">Purchase Unit</th>
                            <th class="px-3 py-3 text-right">Ordered Quantity</th>
                            <th class="px-3 py-3 text-right">Previously Received</th>
                            <th class="px-3 py-3 text-right">Remaining Quantity</th>
                            <th class="px-3 py-3">Received Quantity</th>
                            <th class="px-3 py-3">Stock Increase</th>
                            <th class="px-3 py-3">Supplier Unit Cost</th>
                            <th class="px-3 py-3">Receive Into Location</th>
                            @if ($showBatchColumn)
                                <th class="px-3 py-3">Batch Number</th>
                            @endif
                            @if ($showExpiryColumn)
                                <th class="px-3 py-3">Expiry Date</th>
                            @endif
                            <th class="px-3 py-3">Line Notes</th>
                            <th class="px-3 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($purchase->items as $item)
                            @php($isReceivingLine = (float) ($lines[$item->id]['quantity'] ?? 0) > 0)
                            <tr class="align-top">
                                <td class="px-3 py-3 font-black">
                                    {{ $item->product?->displayName() }}
                                    @if ($item->sizeLabel())
                                        <p class="text-xs font-bold text-cyan-700 dark:text-cyan-200">Size: {{ $item->sizeLabel() }}</p>
                                    @endif
                                </td>
                                <td class="px-3 py-3 font-mono">{{ $item->product?->sku }}</td>
                                <td class="px-3 py-3">
                                    {{ $item->purchase_unit_code_snapshot ?: $item->purchaseUnit?->short_name }}
                                    <span class="mt-1 block text-[11px] text-slate-500">1 {{ $item->purchase_unit_code_snapshot ?: $item->purchaseUnit?->short_name }} = {{ \App\Support\NumberFormatter::quantity($item->purchaseFactor()) }} {{ $item->stock_unit_code_snapshot ?: $item->stockUnit?->short_name }}</span>
                                </td>
                                <td class="px-3 py-3 text-right">{{ \App\Support\NumberFormatter::quantity($item->ordered_quantity) }}</td>
                                <td class="px-3 py-3 text-right">{{ \App\Support\NumberFormatter::quantity($item->received_quantity) }}</td>
                                <td class="px-3 py-3 text-right font-bold">{{ \App\Support\NumberFormatter::quantity($item->remainingQuantity()) }}</td>
                                <td class="px-3 py-3">
                                    <input wire:model.live="lines.{{ $item->id }}.quantity" type="number" step="{{ $item->purchaseUnit?->measurementType?->code === \App\Models\MeasurementType::COUNT ? '1' : '0.0001' }}" max="{{ $item->remainingQuantity() }}" class="w-32 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950">
                                    @error("lines.{$item->id}.quantity") <span class="block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                                </td>
                                <td class="px-3 py-3 font-bold">
                                    {{ \App\Support\NumberFormatter::quantity($item->stockQuantity((float) ($lines[$item->id]['quantity'] ?? 0))) }}
                                    {{ $item->stock_unit_code_snapshot ?: $item->stockUnit?->short_name }}
                                </td>
                                <td class="px-3 py-3">
                                    TZS {{ \App\Support\NumberFormatter::money($item->cost_price) }}
                                </td>
                                <td class="px-3 py-3">
                                    <select wire:model="lines.{{ $item->id }}.stock_location_id" class="w-52 rounded-lg border border-slate-200 bg-white px-3 py-2 dark:border-slate-700 dark:bg-navy-950">
                                        @foreach ($locations as $location)
                                            <option value="{{ $location->id }}">{{ InventorySettings::stockLocationLabel($location) }}</option>
                                        @endforeach
                                    </select>
                                    @error("lines.{$item->id}.stock_location_id") <span class="block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                                </td>
                                @if ($showBatchColumn)
                                    <td class="px-3 py-3">
                                        @if ($item->product?->tracks_batch || $item->product?->tracks_expiry)
                                            <input wire:model="lines.{{ $item->id }}.batch_number" @required($item->product?->tracks_batch && $isReceivingLine) class="w-36 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950">
                                            @if ($item->product?->tracks_batch)
                                                <span class="mt-1 block text-xs font-semibold text-slate-500">Required</span>
                                            @else
                                                <span class="mt-1 block text-xs font-semibold text-slate-500">Optional</span>
                                            @endif
                                            @error("lines.{$item->id}.batch_number") <span class="block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                                        @else
                                            <span class="text-xs font-semibold text-slate-400">Not required</span>
                                        @endif
                                    </td>
                                @endif
                                @if ($showExpiryColumn)
                                    <td class="px-3 py-3">
                                        @if ($item->product?->tracks_expiry)
                                            <input wire:model="lines.{{ $item->id }}.expiry_date" type="date" min="{{ $received_date }}" @required($isReceivingLine) class="w-40 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950">
                                            <span class="mt-1 block text-xs font-semibold text-slate-500">Required</span>
                                            @error("lines.{$item->id}.expiry_date") <span class="block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                                        @else
                                            <span class="text-xs font-semibold text-slate-400">Not required</span>
                                        @endif
                                    </td>
                                @endif
                                <td class="px-3 py-3"><input wire:model="lines.{{ $item->id }}.notes" class="w-48 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></td>
                                <td class="px-3 py-3"><span class="{{ $item->remainingQuantity() > 0 ? 'badge-warning' : 'badge-success' }}">{{ $item->remainingQuantity() > 0 ? 'Open' : 'Done' }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                <div class="flex items-center justify-between gap-3">
                    <div><h2 class="font-black">Additional / Landed Costs</h2><p class="text-xs text-slate-500">Supplier cost is the price paid for the goods. Landed cost includes expenses to bring them into stock.</p></div>
                    <button type="button" wire:click="addAdditionalCost" class="rounded-lg bg-cyan-700 px-3 py-2 text-xs font-bold text-white">Add Cost</button>
                </div>
                @if (auth()->user()?->hasAnyRole(['Super Admin', 'Admin']))
                    <div class="mt-3 flex flex-wrap items-end gap-2">
                        <label class="text-xs font-bold">New Cost Type
                            <input wire:model="new_cost_type" maxlength="100" class="mt-1 block rounded-lg border border-slate-200 p-2 dark:bg-navy-950" placeholder="e.g. Port charges">
                        </label>
                        <button type="button" wire:click="addCostType" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold dark:border-slate-700">Add Type</button>
                        @error('new_cost_type') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                @endif
                @foreach ($additional_costs as $index => $cost)
                    <div wire:key="receipt-cost-{{ $index }}" class="mt-3 grid gap-2 md:grid-cols-3 xl:grid-cols-7">
                        <label class="text-xs font-bold">Cost Type
                            <select wire:model="additional_costs.{{ $index }}.type_id" class="mt-1 w-full rounded-lg border border-slate-200 bg-white p-2 dark:bg-navy-950">
                                <option value="">Select type</option>
                                @foreach ($costTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach
                            </select>
                            @error("additional_costs.{$index}.type_id") <span class="text-red-600">{{ $message }}</span> @enderror
                        </label>
                        <label class="text-xs font-bold">Amount
                            <input wire:model.live="additional_costs.{{ $index }}.amount" type="number" min="0.01" step="0.01" class="mt-1 w-full rounded-lg border border-slate-200 p-2 dark:bg-navy-950">
                            @error("additional_costs.{$index}.amount") <span class="text-red-600">{{ $message }}</span> @enderror
                        </label>
                        <label class="text-xs font-bold">Paid To / Payee<input wire:model="additional_costs.{{ $index }}.payee" class="mt-1 w-full rounded-lg border border-slate-200 p-2 dark:bg-navy-950"></label>
                        <label class="text-xs font-bold">Payment Method<input wire:model="additional_costs.{{ $index }}.payment_method" class="mt-1 w-full rounded-lg border border-slate-200 p-2 dark:bg-navy-950"></label>
                        <label class="text-xs font-bold">Payment Reference<input wire:model="additional_costs.{{ $index }}.payment_reference" class="mt-1 w-full rounded-lg border border-slate-200 p-2 dark:bg-navy-950"></label>
                        <label class="text-xs font-bold">Notes<input wire:model="additional_costs.{{ $index }}.notes" class="mt-1 w-full rounded-lg border border-slate-200 p-2 dark:bg-navy-950"></label>
                        <button type="button" wire:click="removeAdditionalCost({{ $index }})" class="self-end rounded-lg border border-red-300 p-2 text-xs font-bold text-red-700">Remove</button>
                    </div>
                @endforeach
            </div>

            <div class="grid gap-3 md:grid-cols-5">
                @foreach ([
                    'Selected Products' => number_format($summary['selected_lines']),
                    'Quantity to Receive' => \App\Support\NumberFormatter::quantity($summary['quantity']),
                    'Purchase Goods Value' => 'TZS '.\App\Support\NumberFormatter::money($summary['cost']),
                    'Additional Costs' => 'TZS '.\App\Support\NumberFormatter::money($summary['additional']),
                    'Total Landed Cost' => 'TZS '.\App\Support\NumberFormatter::money($summary['landed']),
                    'Receiving Locations' => number_format($summary['locations']),
                    'Remaining After Receipt' => \App\Support\NumberFormatter::quantity($summary['remaining_after']),
                ] as $label => $value)
                    <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                        <p class="text-xs font-bold uppercase text-slate-500">{{ $label }}</p>
                        <p class="mt-2 text-lg font-black">{{ $value }}</p>
                    </div>
                @endforeach
            </div>

            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="openConfirmation" class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Review Receipt</button>
                <a href="{{ route('purchases.show', $purchase->id) }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Cancel</a>
            </div>
        </form>
    </x-card>

    <x-modal name="confirm-receiving" maxWidth="2xl">
        <div class="p-5">
            <h2 class="text-lg font-black">Confirm Purchase Receiving</h2>
            <div class="mt-4 space-y-2 text-sm">
                <p><span class="font-bold">Purchase:</span> {{ $purchase->reference_number }}</p>
                <p><span class="font-bold">Supplier:</span> {{ $purchase->supplier?->name }}</p>
                <p><span class="font-bold">Receiving Date:</span> {{ $received_date }}</p>
                <p><span class="font-bold">Total Quantity:</span> {{ \App\Support\NumberFormatter::quantity($summary['quantity']) }}</p>
                <p><span class="font-bold">Goods Value:</span> TZS {{ \App\Support\NumberFormatter::money($summary['cost']) }}</p>
                <p><span class="font-bold">Additional Costs:</span> TZS {{ \App\Support\NumberFormatter::money($summary['additional']) }}</p>
                <p><span class="font-bold">Total Landed Cost:</span> TZS {{ \App\Support\NumberFormatter::money($summary['landed']) }}</p>
            </div>
            <div class="mt-4 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                @foreach ($this->locationBreakdown() as $row)
                    <div class="flex justify-between gap-4 py-1 text-sm">
                        <span class="font-bold">{{ $row['name'] }}</span>
                        <span>{{ \App\Support\NumberFormatter::quantity($row['quantity']) }}</span>
                    </div>
                @endforeach
            </div>
            <div class="mt-5 flex flex-wrap justify-end gap-2">
                <button type="button" x-on:click="$dispatch('close-modal', 'confirm-receiving')" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Cancel</button>
                <button type="button" wire:click="saveDraft" class="rounded-xl border border-cyan-200 px-4 py-2.5 text-sm font-black text-cyan-700 dark:border-cyan-500/30 dark:text-cyan-200">Save as Draft</button>
                <button type="button" wire:click="postReceipt" class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Confirm and Post Receipt</button>
            </div>
        </div>
    </x-modal>
</div>
