<?php

use App\Models\Purchase;
use App\Services\InventoryService;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['purchase' => null, 'received_date' => '', 'notes' => '', 'receivedQuantities' => []]);

mount(function (Purchase $purchase) {
    abort_if($purchase->status === 'cancelled' || $purchase->status === 'received', 403);

    $this->purchase = $purchase->load(['supplier', 'branch', 'items.product.unit']);
    $this->received_date = now()->toDateString();
    $this->receivedQuantities = $this->purchase->items->mapWithKeys(fn ($item) => [$item->id => 0])->all();
});

$receiveAll = function () {
    foreach ($this->purchase->items as $item) {
        $this->receivedQuantities[$item->id] = $item->remainingQuantity();
    }
};

$submitReceiving = function (InventoryService $inventory) {
    $this->validate([
        'received_date' => ['required', 'date'],
        'notes' => ['nullable', 'string', 'max:1000'],
        'receivedQuantities' => ['array'],
        'receivedQuantities.*' => ['nullable', 'numeric', 'min:0'],
    ]);

    $inventory->receivePurchase($this->purchase, $this->receivedQuantities, $this->received_date, auth()->id(), $this->notes);

    session()->flash('success', 'Purchase received into Main Store.');
    $this->redirectRoute('purchases.show', $this->purchase, navigate: true);
};

?>

<div>
    <x-page-header title="Receive Purchase" description="Receive ordered items into Main Store only." :breadcrumbs="['Dashboard' => route('dashboard'), 'Purchases' => route('purchases.index'), 'Receive' => null]">
        <button type="button" wire:click="receiveAll" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Receive All</button>
    </x-page-header>

    <x-card title="{{ $purchase->reference_number }} / {{ $purchase->supplier?->name }}">
        <form wire:submit="submitReceiving" class="space-y-5">
            <div class="grid gap-4 md:grid-cols-2">
                <x-form-input label="Receiving Date" name="received_date" type="date" wire:model="received_date" required />
                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">Notes
                    <textarea wire:model="notes" class="mt-1 block min-h-20 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
                </label>
            </div>

            <x-table :headers="['Product', 'Ordered', 'Already Received', 'Remaining', 'Receive Qty']">
                @foreach ($purchase->items as $item)
                    <tr>
                        <td class="px-4 py-3 font-black">{{ $item->product?->name }}</td>
                        <td class="px-4 py-3">{{ number_format((float) $item->ordered_quantity, 2) }}</td>
                        <td class="px-4 py-3">{{ number_format((float) $item->received_quantity, 2) }}</td>
                        <td class="px-4 py-3 font-bold">{{ number_format($item->remainingQuantity(), 2) }}</td>
                        <td class="px-4 py-3">
                            <input wire:model="receivedQuantities.{{ $item->id }}" type="number" step="0.01" max="{{ $item->remainingQuantity() }}" class="w-32 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950">
                            @error("receivedQuantities.{$item->id}") <span class="block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                        </td>
                    </tr>
                @endforeach
            </x-table>

            <div class="flex gap-2">
                <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Submit Receiving</button>
                <a href="{{ route('purchases.show', $purchase) }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Cancel</a>
            </div>
        </form>
    </x-card>
</div>
