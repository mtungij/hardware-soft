<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockTransfer;
use App\Services\InventoryService;
use App\Support\InventorySettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

abort_unless(InventorySettings::warehouseEnabled(), 403);

state(['branch_id' => '', 'transfer_number' => '', 'from_location_id' => '', 'to_location_id' => '', 'transfer_date' => '', 'notes' => '', 'items' => []]);

mount(function (InventoryService $inventory) {
    $this->branch_id = (string) (auth()->user()->branch_id ?: Branch::where('code', 'MAIN')->value('id'));
    $this->transfer_number = $inventory->generateTransferNumber();
    $this->from_location_id = (string) $inventory->getMainStoreLocation((int) $this->branch_id)->id;
    $this->to_location_id = (string) $inventory->getDispensingLocation((int) $this->branch_id)->id;
    $this->transfer_date = now()->toDateString();
    $this->items = [['product_id' => '', 'quantity' => '1', 'notes' => '']];
});

$addItem = function () {
    $this->items[] = ['product_id' => '', 'quantity' => '1', 'notes' => ''];
};

$removeItem = function (int $index) {
    unset($this->items[$index]);
    $this->items = array_values($this->items);
};

$availableQuantity = function (?string $productId) {
    if (! $productId || ! $this->from_location_id || ! $this->branch_id) {
        return 0;
    }

    return app(InventoryService::class)->getProductStock((int) $productId, (int) $this->from_location_id, (int) $this->branch_id);
};

$saveTransfer = function (string $status, InventoryService $inventory) {
    $validated = $this->validate([
        'branch_id' => ['required', 'exists:branches,id'],
        'transfer_number' => ['required', 'string', 'max:255', 'unique:stock_transfers,transfer_number'],
        'from_location_id' => ['required', 'exists:stock_locations,id'],
        'to_location_id' => ['required', 'exists:stock_locations,id', 'different:from_location_id'],
        'transfer_date' => ['required', 'date'],
        'notes' => ['nullable', 'string', 'max:1000'],
        'items' => ['required', 'array', 'min:1'],
        'items.*.product_id' => ['required', 'exists:products,id'],
        'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        'items.*.notes' => ['nullable', 'string', 'max:1000'],
    ]);

    $productIds = collect($validated['items'])->pluck('product_id');
    if ($productIds->duplicates()->isNotEmpty()) {
        throw ValidationException::withMessages(['items' => 'Duplicate product rows are not allowed.']);
    }

    $from = StockLocation::findOrFail($validated['from_location_id']);
    $to = StockLocation::findOrFail($validated['to_location_id']);
    if ($from->status !== 'active' || $to->status !== 'active') {
        throw ValidationException::withMessages(['location' => 'Transfers require active locations.']);
    }

    foreach ($validated['items'] as $item) {
        $product = Product::findOrFail($item['product_id']);
        if ($product->status !== 'active') {
            throw ValidationException::withMessages(['items' => 'Inactive products cannot be transferred.']);
        }
        $available = $inventory->getProductStock((int) $item['product_id'], (int) $validated['from_location_id'], (int) $validated['branch_id']);
        if ((float) $item['quantity'] > $available) {
            throw ValidationException::withMessages(['items' => "{$product->name} quantity exceeds available Main Store stock."]);
        }
    }

    $transfer = DB::transaction(function () use ($validated, $status) {
        $transfer = StockTransfer::create([
            'branch_id' => $validated['branch_id'],
            'transfer_number' => $validated['transfer_number'],
            'from_location_id' => $validated['from_location_id'],
            'to_location_id' => $validated['to_location_id'],
            'transfer_date' => $validated['transfer_date'],
            'status' => 'draft',
            'notes' => $validated['notes'],
            'created_by' => auth()->id(),
        ]);

        foreach ($validated['items'] as $item) {
            $transfer->items()->create($item);
        }

        return $transfer;
    });

    if ($status === 'completed') {
        $inventory->completeStockTransfer($transfer->id, auth()->id());
    }

    session()->flash('success', $status === 'completed' ? 'Transfer completed.' : 'Transfer saved as draft.');
    $this->redirectRoute('stock-transfers.index', navigate: true);
};

?>

<div>
    <x-page-header title="Create Stock Transfer" description="Move stock from Main Store to Dispensing Area." :breadcrumbs="['Dashboard' => route('dashboard'), 'Stock Transfers' => route('stock-transfers.index'), 'Create' => null]" />

    <x-card>
        <form class="space-y-6">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">Branch
                    <select wire:model="branch_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        @foreach (Branch::where('status', 'active')->orderBy('name')->get() as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </label>
                <x-form-input label="Transfer Number" name="transfer_number" wire:model="transfer_number" required />
                <x-form-input label="Transfer Date" name="transfer_date" type="date" wire:model="transfer_date" required />
                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">From Location
                    <select wire:model="from_location_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        @foreach (StockLocation::where('type', 'store')->where('status', 'active')->orderBy('name')->get() as $location)
                            <option value="{{ $location->id }}">{{ $location->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">To Location
                    <select wire:model="to_location_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        @foreach (StockLocation::where('type', 'dispensing')->where('status', 'active')->orderBy('name')->get() as $location)
                            <option value="{{ $location->id }}">{{ $location->name }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <x-table :headers="['Product', 'Available Main Store', 'Unit', 'Transfer Qty', 'Notes', '']">
                @foreach ($items as $index => $item)
                    @php
                        $product = $item['product_id'] ? Product::with('unit')->find($item['product_id']) : null;
                        $available = $this->availableQuantity($item['product_id']);
                    @endphp
                    <tr>
                        <td class="px-4 py-3">
                            <select wire:model.live="items.{{ $index }}.product_id" class="w-72 rounded-lg border border-slate-200 bg-white px-3 py-2 dark:border-slate-700 dark:bg-navy-950">
                                <option value="">Select product</option>
                                @foreach (Product::where('status', 'active')->orderBy('name')->get() as $productOption)
                                    <option value="{{ $productOption->id }}">{{ $productOption->name }} / {{ $productOption->sku }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="px-4 py-3 font-black">{{ number_format($available, 2) }}</td>
                        <td class="px-4 py-3">{{ $product?->unit?->short_name ?? '-' }}</td>
                        <td class="px-4 py-3"><input wire:model="items.{{ $index }}.quantity" type="number" step="0.01" class="w-32 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></td>
                        <td class="px-4 py-3"><input wire:model="items.{{ $index }}.notes" class="w-56 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></td>
                        <td class="px-4 py-3"><button type="button" wire:click="removeItem({{ $index }})" class="text-sm font-bold text-red-600">Remove</button></td>
                    </tr>
                @endforeach
            </x-table>

            <button type="button" wire:click="addItem" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-black dark:border-slate-700">Add Item</button>

            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">Notes
                <textarea wire:model="notes" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
            </label>

            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="saveTransfer('draft')" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Save as Draft</button>
                <button type="button" wire:click="saveTransfer('completed')" class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Complete Transfer</button>
                <a href="{{ route('stock-transfers.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Cancel</a>
            </div>
        </form>
    </x-card>
</div>
