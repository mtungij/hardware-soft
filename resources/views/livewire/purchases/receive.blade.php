<?php

use App\Models\ProductLocationSetting;
use App\Models\Purchase;
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
]);

mount(function (Purchase $purchase, InventoryService $inventory) {
    abort_if($purchase->status === 'cancelled' || $purchase->status === 'received', 403);

    $this->purchase = $purchase->load(['supplier', 'branch', 'creator', 'items.product.unit']);
    $this->purchase_id = $purchase->id;
    $this->grn_number = $inventory->generateGrnNumber();
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
            'unit_cost' => (string) $item->cost_price,
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
        $cost += $lineQuantity * (float) ($line['unit_cost'] ?? $item->cost_price);
        $locations->push((int) ($line['stock_location_id'] ?? 0));
    }

    return [
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

    $this->validate([
        'grn_number' => ['required', 'string', 'max:255', Rule::unique('goods_receiving_notes', 'grn_number')],
        'received_date' => ['required', 'date'],
        'supplier_delivery_note_number' => ['nullable', 'string', 'max:255'],
        'supplier_invoice_number' => ['nullable', 'string', 'max:255'],
        'default_stock_location_id' => ['required', Rule::in($locationIds)],
        'notes' => ['nullable', 'string', 'max:1000'],
        'lines' => ['required', 'array'],
        'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
        'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
        'lines.*.stock_location_id' => ['required', Rule::in($locationIds)],
        'lines.*.batch_number' => ['nullable', 'string', 'max:255'],
        'lines.*.expiry_date' => ['nullable', 'date'],
        'lines.*.notes' => ['nullable', 'string', 'max:1000'],
    ]);

    $purchase = Purchase::query()->with('items')->findOrFail($this->purchase_id);
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
        'default_stock_location_id' => (int) $this->default_stock_location_id,
        'supplier_delivery_note_number' => $this->supplier_delivery_note_number ?: null,
        'supplier_invoice_number' => $this->supplier_invoice_number ?: null,
        'status' => 'draft',
    ]);

    session()->flash('success', 'Goods receipt draft saved.');
    $this->redirectRoute('purchases.show', ['purchase' => $purchase->id], navigate: true);
};

$postReceipt = function (InventoryService $inventory) {
    $this->validateReceiving();

    $purchase = Purchase::query()->findOrFail($this->purchase_id);
    $inventory->receivePurchase($purchase, $this->lines, $this->received_date, auth()->id(), $this->notes, [
        'grn_number' => $this->grn_number,
        'default_stock_location_id' => (int) $this->default_stock_location_id,
        'supplier_delivery_note_number' => $this->supplier_delivery_note_number ?: null,
        'supplier_invoice_number' => $this->supplier_invoice_number ?: null,
        'status' => 'posted',
    ]);

    session()->flash('success', 'Purchase received successfully.');
    $this->redirectRoute('purchases.show', ['purchase' => $purchase->id], navigate: true);
};

?>

<div>
    @php
        $locations = $this->availableReceivingLocations();
        $locationOptions = $locations->keyBy('id');
        $summary = $this->summary();
        $totalOrdered = $purchase->items->sum('ordered_quantity');
        $totalReceived = $purchase->items->sum('received_quantity');
        $totalRemaining = $purchase->items->sum(fn ($item) => $item->remainingQuantity());
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
            'Total Ordered Quantity' => number_format((float) $totalOrdered, 2),
            'Previously Received Quantity' => number_format((float) $totalReceived, 2),
            'Remaining Quantity' => number_format((float) $totalRemaining, 2),
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
                <x-form-input label="Goods Receipt Number" name="grn_number" wire:model="grn_number" required />
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
                <table class="min-w-[1320px] w-full text-sm">
                    <thead class="sticky top-0 z-10 bg-slate-100 text-left text-xs uppercase text-slate-500 dark:bg-slate-800">
                        <tr>
                            <th class="px-3 py-3">Product</th>
                            <th class="px-3 py-3">SKU</th>
                            <th class="px-3 py-3">Unit</th>
                            <th class="px-3 py-3 text-right">Ordered Quantity</th>
                            <th class="px-3 py-3 text-right">Previously Received</th>
                            <th class="px-3 py-3 text-right">Remaining Quantity</th>
                            <th class="px-3 py-3">Quantity to Receive</th>
                            <th class="px-3 py-3">Unit Cost</th>
                            <th class="px-3 py-3">Receive Into Location</th>
                            <th class="px-3 py-3">Batch Number</th>
                            <th class="px-3 py-3">Expiry Date</th>
                            <th class="px-3 py-3">Line Notes</th>
                            <th class="px-3 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($purchase->items as $item)
                            <tr class="align-top">
                                <td class="px-3 py-3 font-black">{{ $item->product?->name }}</td>
                                <td class="px-3 py-3 font-mono">{{ $item->product?->sku }}</td>
                                <td class="px-3 py-3">{{ $item->product?->unit?->short_name }}</td>
                                <td class="px-3 py-3 text-right">{{ number_format((float) $item->ordered_quantity, 2) }}</td>
                                <td class="px-3 py-3 text-right">{{ number_format((float) $item->received_quantity, 2) }}</td>
                                <td class="px-3 py-3 text-right font-bold">{{ number_format($item->remainingQuantity(), 2) }}</td>
                                <td class="px-3 py-3">
                                    <input wire:model.live="lines.{{ $item->id }}.quantity" type="number" step="0.01" max="{{ $item->remainingQuantity() }}" class="w-32 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950">
                                    @error("lines.{$item->id}.quantity") <span class="block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                                </td>
                                <td class="px-3 py-3">
                                    <input wire:model="lines.{{ $item->id }}.unit_cost" type="number" step="0.01" class="w-32 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950">
                                    @error("lines.{$item->id}.unit_cost") <span class="block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                                </td>
                                <td class="px-3 py-3">
                                    <select wire:model="lines.{{ $item->id }}.stock_location_id" class="w-52 rounded-lg border border-slate-200 bg-white px-3 py-2 dark:border-slate-700 dark:bg-navy-950">
                                        @foreach ($locations as $location)
                                            <option value="{{ $location->id }}">{{ InventorySettings::stockLocationLabel($location) }}</option>
                                        @endforeach
                                    </select>
                                    @error("lines.{$item->id}.stock_location_id") <span class="block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                                </td>
                                <td class="px-3 py-3"><input wire:model="lines.{{ $item->id }}.batch_number" class="w-36 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></td>
                                <td class="px-3 py-3"><input wire:model="lines.{{ $item->id }}.expiry_date" type="date" class="w-40 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></td>
                                <td class="px-3 py-3"><input wire:model="lines.{{ $item->id }}.notes" class="w-48 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></td>
                                <td class="px-3 py-3"><span class="{{ $item->remainingQuantity() > 0 ? 'badge-warning' : 'badge-success' }}">{{ $item->remainingQuantity() > 0 ? 'Open' : 'Done' }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="grid gap-3 md:grid-cols-5">
                @foreach ([
                    'Selected Products' => number_format($summary['selected_lines']),
                    'Quantity to Receive' => number_format($summary['quantity'], 2),
                    'Total Receiving Cost' => 'TZS '.number_format($summary['cost'], 2),
                    'Receiving Locations' => number_format($summary['locations']),
                    'Remaining After Receipt' => number_format($summary['remaining_after'], 2),
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
                <p><span class="font-bold">Total Quantity:</span> {{ number_format($summary['quantity'], 2) }}</p>
                <p><span class="font-bold">Total Cost:</span> TZS {{ number_format($summary['cost'], 2) }}</p>
            </div>
            <div class="mt-4 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                @foreach ($this->locationBreakdown() as $row)
                    <div class="flex justify-between gap-4 py-1 text-sm">
                        <span class="font-bold">{{ $row['name'] }}</span>
                        <span>{{ number_format($row['quantity'], 2) }}</span>
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
