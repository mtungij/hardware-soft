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
    StockAdjustment::whereKey($adjustmentId)->whereIn('status', ['pending', 'pending_approval'])->update([
        'status' => 'rejected',
        'approved_by' => auth()->id(),
        'approved_at' => now(),
    ]);
    session()->flash('success', 'Stock adjustment rejected.');
};

?>

<div>
    <x-page-header title="Approve Stock Adjustments" description="Approve pending location-based stock adjustments." :breadcrumbs="['Dashboard' => route('dashboard'), 'Stock Adjustments' => route('stock-adjustments.index'), 'Approve' => null]" />

    <x-card>
        @php $adjustments = StockAdjustment::with(['product', 'stockLocation', 'requester'])->withCount('lines')->whereIn('status', ['pending', 'pending_approval'])->latest()->get(); @endphp
        <x-table :headers="['Reference', 'Date', 'Product(s)', 'Location', 'Type', 'Qty', 'Reason', 'Requested By', 'Actions']">
            @forelse ($adjustments as $adjustment)
                <tr>
                    <td class="px-4 py-3 font-bold">{{ $adjustment->reference_number ?: 'ADJ-'.$adjustment->id }}</td>
                    <td class="px-4 py-3">{{ $adjustment->adjustment_date?->format('d M Y') ?? $adjustment->created_at?->format('d M Y') }}</td>
                    <td class="px-4 py-3 font-bold">{{ $adjustment->lines_count > 1 ? $adjustment->lines_count.' products' : $adjustment->product?->name }}</td>
                    <td class="px-4 py-3">{{ $adjustment->stockLocation?->name }}</td>
                    <td class="px-4 py-3">{{ str($adjustment->adjustment_type)->replace('_', ' ')->title() }}</td>
                    <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($adjustment->quantity) }}</td>
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
                <tr><td colspan="9" class="px-4 py-8 text-center text-slate-500">No pending adjustments.</td></tr>
            @endforelse
        </x-table>
    </x-card>
</div>
