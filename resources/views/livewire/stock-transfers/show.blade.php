<?php

use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Support\InventorySettings;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

abort_unless(InventorySettings::warehouseEnabled(), 403);

state(['stockTransfer' => null]);

mount(function (StockTransfer $stockTransfer) {
    $this->stockTransfer = $stockTransfer->load(['branch', 'fromLocation', 'toLocation', 'createdBy', 'completedBy', 'items.product.unit']);
});

?>

<div>
    <x-page-header title="Stock Transfer Details" description="Transfer header, items, and stock movement references." :breadcrumbs="['Dashboard' => route('dashboard'), 'Stock Transfers' => route('stock-transfers.index'), $stockTransfer->transfer_number => null]">
        <a href="{{ route('stock-transfers.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Back</a>
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-3">
        <x-card title="Transfer Summary">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Transfer #</dt><dd class="font-black">{{ $stockTransfer->transfer_number }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Date</dt><dd>{{ $stockTransfer->transfer_date->format('d M Y') }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">From</dt><dd>{{ $stockTransfer->fromLocation?->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">To</dt><dd>{{ $stockTransfer->toLocation?->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Created By</dt><dd>{{ $stockTransfer->createdBy?->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Completed By</dt><dd>{{ $stockTransfer->completedBy?->name ?? '-' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Completed Date</dt><dd>{{ $stockTransfer->completed_at?->format('d M Y H:i') ?? '-' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Status</dt><dd><span class="{{ $stockTransfer->status === 'completed' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($stockTransfer->status) }}</span></dd></div>
            </dl>
        </x-card>

        <x-card title="Transfer Items" class="xl:col-span-2">
            <x-table :headers="['Product', 'SKU', 'Unit', 'Quantity', 'Notes']">
                @foreach ($stockTransfer->items as $item)
                    <tr>
                        <td class="px-4 py-3 font-black">{{ $item->product?->name }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $item->product?->sku }}</td>
                        <td class="px-4 py-3">{{ $item->product?->unit?->short_name }}</td>
                        <td class="px-4 py-3">{{ number_format((float) $item->quantity, 2) }}</td>
                        <td class="px-4 py-3">{{ $item->notes ?? '-' }}</td>
                    </tr>
                @endforeach
            </x-table>
        </x-card>
    </div>

    <x-card title="Stock Movement References" class="mt-6">
        @php
            $movements = StockMovement::with(['product', 'stockLocation'])
                ->where('reference_type', StockTransfer::class)
                ->where('reference_id', $stockTransfer->id)
                ->latest()
                ->get();
        @endphp
        <x-table :headers="['Date', 'Product', 'Location', 'Type', 'Quantity']">
            @forelse ($movements as $movement)
                <tr>
                    <td class="px-4 py-3">{{ $movement->movement_date->format('d M Y') }}</td>
                    <td class="px-4 py-3">{{ $movement->product?->name }}</td>
                    <td class="px-4 py-3">{{ $movement->stockLocation?->name }}</td>
                    <td class="px-4 py-3">{{ $movement->movement_type }}</td>
                    <td class="px-4 py-3">{{ number_format((float) $movement->quantity, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">No stock movements yet. Draft transfers do not affect stock.</td></tr>
            @endforelse
        </x-table>
    </x-card>
</div>
