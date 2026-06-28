<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Services\InventoryService;
use App\Support\InventorySettings;
use Illuminate\Validation\Rule;
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
    $this->stock_location_id = (string) InventorySettings::defaultLocation((int) $this->branch_id)->id;
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

$save = function (InventoryService $inventory) {
    abort_unless(InventorySettings::directStockInAllowed(), 403);

    $data = $this->validate();

    if (! InventorySettings::warehouseEnabled()) {
        $data['stock_location_id'] = InventorySettings::receivingLocation((int) $data['branch_id'])->id;
    }

    $inventory->directStockIn($data, auth()->id());

    $this->reset(['product_id', 'quantity', 'cost_price', 'selling_price', 'notes']);
    $this->quantity = '1';
    $this->reason = 'Opening Stock';
    $this->movement_date = now()->toDateString();
    $this->stock_location_id = (string) InventorySettings::defaultLocation((int) $this->branch_id)->id;

    session()->flash('success', 'Direct stock in saved.');
};

?>

<div>
    <x-page-header title="Direct Stock In" description="Add stock directly without supplier or purchase order." :breadcrumbs="['Dashboard' => route('dashboard'), 'Direct Stock In' => null]" />

    <div class="grid gap-6 xl:grid-cols-[420px_1fr]">
        <x-card title="Stock Entry" description="This creates a positive stock movement. Product quantity is calculated from movements.">
            <form wire:submit="save" class="space-y-4">
                <label class="block text-sm font-bold">Product
                    <select wire:model.live="product_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        <option value="">Select product</option>
                        @foreach (Product::where('status', 'active')->orderBy('name')->get() as $product)
                            <option value="{{ $product->id }}">{{ $product->name }} / {{ $product->sku }}</option>
                        @endforeach
                    </select>
                    @error('product_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-form-input label="Quantity" name="quantity" type="number" step="0.01" wire:model="quantity" required />
                    <x-form-input label="Movement Date" name="movement_date" type="date" wire:model="movement_date" required />
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-money-input label="Cost Price" name="cost_price" wire:model="cost_price" required />
                    <x-money-input label="Selling Price" name="selling_price" wire:model="selling_price" />
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

                <button class="w-full rounded-xl bg-cyan-500 px-4 py-3 text-sm font-black text-white shadow-lg shadow-cyan-500/20">Save Direct Stock In</button>
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
