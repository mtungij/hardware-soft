<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Services\InventoryService;
use Illuminate\Validation\ValidationException;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['branch_id' => '', 'product_id' => '', 'stock_location_id' => '', 'adjustment_type' => 'adjustment_in', 'quantity' => '1', 'reason' => '', 'notes' => '']);

mount(function (InventoryService $inventory) {
    $this->branch_id = (string) (auth()->user()->branch_id ?: Branch::where('code', 'MAIN')->value('id'));
    $this->stock_location_id = (string) $inventory->getMainStoreLocation((int) $this->branch_id)->id;
});

$save = function (InventoryService $inventory) {
    $validated = $this->validate([
        'branch_id' => ['required', 'exists:branches,id'],
        'product_id' => ['required', 'exists:products,id'],
        'stock_location_id' => ['required', 'exists:stock_locations,id'],
        'adjustment_type' => ['required', 'in:adjustment_in,adjustment_out,damage_out'],
        'quantity' => ['required', 'numeric', 'gt:0'],
        'reason' => ['required', 'string', 'max:255'],
        'notes' => ['nullable', 'string', 'max:1000'],
    ]);

    if (in_array($validated['adjustment_type'], StockMovement::NEGATIVE_TYPES, true)) {
        $available = $inventory->getProductStock((int) $validated['product_id'], (int) $validated['stock_location_id'], (int) $validated['branch_id']);
        if ((float) $validated['quantity'] > $available) {
            throw ValidationException::withMessages(['quantity' => 'Adjustment would create negative stock.']);
        }
    }

    StockAdjustment::create([
        ...$validated,
        'status' => 'pending',
        'requested_by' => auth()->id(),
    ]);

    session()->flash('success', 'Stock adjustment submitted for approval.');
    $this->redirectRoute('stock-adjustments.index', navigate: true);
};

?>

<div>
    <x-page-header title="Create Stock Adjustment" description="Adjust Main Store stock through an approval workflow." :breadcrumbs="['Dashboard' => route('dashboard'), 'Stock Adjustments' => route('stock-adjustments.index'), 'Create' => null]" />

    <x-card>
        <form wire:submit="save" class="grid gap-4 md:grid-cols-2">
            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">Product
                <select wire:model="product_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">Select product</option>
                    @foreach (Product::orderBy('name')->get() as $product)
                        <option value="{{ $product->id }}">{{ $product->name }} / {{ $product->sku }}</option>
                    @endforeach
                </select>
                @error('product_id') <span class="text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
            </label>
            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">Location
                <select wire:model="stock_location_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    @foreach (StockLocation::where('type', 'store')->orderBy('name')->get() as $location)
                        <option value="{{ $location->id }}">{{ $location->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">Adjustment Type
                <select wire:model="adjustment_type" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="adjustment_in">Adjustment In</option>
                    <option value="adjustment_out">Adjustment Out</option>
                    <option value="damage_out">Damage Out</option>
                </select>
            </label>
            <x-form-input label="Quantity" name="quantity" type="number" step="0.01" wire:model="quantity" required />
            <x-form-input label="Reason" name="reason" wire:model="reason" required />
            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200 md:col-span-2">Notes
                <textarea wire:model="notes" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
            </label>
            <div class="flex gap-2 md:col-span-2">
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Submit Adjustment</button>
                <a href="{{ route('stock-adjustments.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Cancel</a>
            </div>
        </form>
    </x-card>
</div>
