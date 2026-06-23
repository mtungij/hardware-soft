<?php

use App\Models\Sale;
use App\Services\InventoryService;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['sale' => null]);

mount(function (Sale $sale) {
    $this->sale = $sale->load(['customer', 'items.product']);
});

$cancelSale = function (InventoryService $inventory) {
    $inventory->cancelSale($this->sale->id, auth()->id());

    session()->flash('success', 'Sale cancelled and stock returned successfully.');
    $this->redirectRoute('sales.index', navigate: true);
};

?>

<div>
    <x-page-header title="Cancel Sale" :description="$sale->sale_number" :breadcrumbs="['Dashboard' => route('dashboard'), 'Sales' => route('sales.index'), $sale->sale_number => route('sales.show', $sale), 'Cancel' => null]" />

    <x-card class="max-w-2xl">
        <div class="rounded-lg bg-red-50 p-4 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">
            Cancelling this sale will create return stock movements for every sold item and reduce the customer balance for any unpaid credit amount.
        </div>

        <div class="mt-5 space-y-2 text-sm">
            <div class="flex justify-between"><span class="text-slate-500">Customer</span><span class="font-bold">{{ $sale->customer?->name ?? 'Walk-in Customer' }}</span></div>
            <div class="flex justify-between"><span class="text-slate-500">Total</span><span class="font-bold">TZS {{ number_format((float) $sale->total_amount, 2) }}</span></div>
            <div class="flex justify-between"><span class="text-slate-500">Balance</span><span class="font-bold">TZS {{ number_format((float) $sale->balance_amount, 2) }}</span></div>
            <div class="flex justify-between"><span class="text-slate-500">Items</span><span class="font-bold">{{ $sale->items->count() }}</span></div>
            <div class="flex justify-between"><span class="text-slate-500">Status</span><span class="font-bold">{{ ucfirst($sale->status) }}</span></div>
        </div>

        <div class="mt-6 flex flex-wrap gap-3">
            <a href="{{ route('sales.show', $sale) }}" wire:navigate class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-bold dark:border-slate-700">Keep Sale</a>
            @if ($sale->status === 'completed')
                <button wire:click="cancelSale" wire:confirm="Cancel this sale and return stock?" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white">Confirm Cancel Sale</button>
            @endif
        </div>
    </x-card>
</div>
