<?php

use App\Models\StockAdjustment;
use App\Services\InventoryService;

use function Livewire\Volt\layout;

layout('layouts.app');

$approve = function (int $adjustmentId, InventoryService $inventory) {
    $inventory->approveAdjustment(StockAdjustment::findOrFail($adjustmentId), auth()->id());
    session()->flash('success', 'Stock adjustment approved and movement created.');
};

$reject = function (int $adjustmentId) {
    StockAdjustment::whereKey($adjustmentId)->where('status', 'pending')->update([
        'status' => 'rejected',
        'approved_by' => auth()->id(),
        'approved_at' => now(),
    ]);
    session()->flash('success', 'Stock adjustment rejected.');
};

?>

<div>
    <x-page-header title="Approve Stock Adjustments" description="Approve pending Main Store adjustments." :breadcrumbs="['Dashboard' => route('dashboard'), 'Stock Adjustments' => route('stock-adjustments.index'), 'Approve' => null]" />

    <x-card>
        @php $adjustments = StockAdjustment::with(['product', 'stockLocation', 'requester'])->where('status', 'pending')->latest()->get(); @endphp
        <x-table :headers="['Product', 'Location', 'Type', 'Qty', 'Reason', 'Requested By', 'Actions']">
            @forelse ($adjustments as $adjustment)
                <tr>
                    <td class="px-4 py-3 font-bold">{{ $adjustment->product?->name }}</td>
                    <td class="px-4 py-3">{{ $adjustment->stockLocation?->name }}</td>
                    <td class="px-4 py-3">{{ $adjustment->adjustment_type }}</td>
                    <td class="px-4 py-3">{{ number_format((float) $adjustment->quantity, 2) }}</td>
                    <td class="px-4 py-3">{{ $adjustment->reason }}</td>
                    <td class="px-4 py-3">{{ $adjustment->requester?->name }}</td>
                    <td class="px-4 py-3">
                        <div class="flex gap-2">
                            <button wire:click="approve({{ $adjustment->id }})" class="rounded-lg bg-build-orange px-3 py-1.5 text-xs font-bold text-white">Approve</button>
                            <button wire:click="reject({{ $adjustment->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">Reject</button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">No pending adjustments.</td></tr>
            @endforelse
        </x-table>
    </x-card>
</div>
