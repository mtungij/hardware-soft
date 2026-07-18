<?php

use App\Models\GoodsReceivingNote;
use App\Support\InventorySettings;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['receipt' => null]);

mount(function (GoodsReceivingNote $receipt) {
    $this->receipt = $receipt->load([
        'branch',
        'purchase.supplier',
        'receiver',
        'postedBy',
        'items.product.unit',
        'items.stockLocation',
    ]);
});

?>

<div>
    @php
        $totalQuantity = $receipt->items->sum('received_quantity');
        $totalCost = $receipt->items->sum(fn ($item) => (float) ($item->total_cost ?: ((float) $item->received_quantity * (float) $item->cost_price)));
        $locations = $receipt->items->map(fn ($item) => $item->stockLocation?->name)->filter()->unique();
    @endphp

    <x-page-header title="Goods Receipt" description="{{ $receipt->grn_number }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Purchases' => route('purchases.index'), $receipt->purchase?->reference_number => route('purchases.show', $receipt->purchase), $receipt->grn_number => null]">
        <a href="{{ route('purchases.show', $receipt->purchase) }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Back</a>
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-3">
        <x-card title="Receipt Summary">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Receipt Number</dt><dd class="font-bold">{{ $receipt->grn_number }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Purchase Number</dt><dd>{{ $receipt->purchase?->reference_number }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Supplier</dt><dd>{{ $receipt->purchase?->supplier?->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Delivery Note</dt><dd>{{ $receipt->supplier_delivery_note_number ?: '-' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Supplier Invoice</dt><dd>{{ $receipt->supplier_invoice_number ?: '-' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Receiving Date</dt><dd>{{ $receipt->received_date?->format('d M Y') }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Received By</dt><dd>{{ $receipt->receiver?->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Posted By</dt><dd>{{ $receipt->postedBy?->name ?? '-' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Status</dt><dd><span class="{{ $receipt->status === 'posted' ? 'badge-success' : ($receipt->status === 'cancelled' ? 'rounded-full bg-red-100 px-2.5 py-1 text-xs font-black text-red-700 dark:bg-red-500/15 dark:text-red-300' : 'badge-warning') }}">{{ ucfirst($receipt->status ?? 'posted') }}</span></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Total Quantity</dt><dd class="font-black">{{ \App\Support\NumberFormatter::quantity($totalQuantity) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Total Cost</dt><dd class="font-black">TZS {{ \App\Support\NumberFormatter::money($totalCost) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Locations</dt><dd>{{ $locations->join(', ') ?: '-' }}</dd></div>
            </dl>
        </x-card>

        <x-card title="Received Products" class="xl:col-span-2">
            <x-table :headers="['Product', 'SKU', 'Ordered', 'Previous', 'Received Now', 'Unit Cost', 'Total Cost', 'Receiving Location', 'Batch', 'Expiry']">
                @foreach ($receipt->items as $item)
                    <tr>
                        <td class="px-4 py-3 font-black">
                            {{ $item->product?->displayName() }}
                            @if ($item->product?->sizeLabel())
                                <p class="text-xs font-bold text-cyan-700 dark:text-cyan-200">Size: {{ $item->product->sizeLabel() }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono">{{ $item->product?->sku }}</td>
                        <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($item->ordered_quantity) }} {{ $item->product?->unit?->short_name }}</td>
                        <td class="px-4 py-3">{{ \App\Support\NumberFormatter::quantity($item->previously_received_quantity) }}</td>
                        <td class="px-4 py-3 font-bold">{{ \App\Support\NumberFormatter::quantity($item->received_quantity) }}</td>
                        <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money(($item->unit_cost ?: $item->cost_price)) }}</td>
                        <td class="px-4 py-3">TZS {{ \App\Support\NumberFormatter::money(($item->total_cost ?: ((float) $item->received_quantity * (float) $item->cost_price))) }}</td>
                        <td class="px-4 py-3">{{ $item->stockLocation ? InventorySettings::stockLocationLabel($item->stockLocation) : '-' }}</td>
                        <td class="px-4 py-3">{{ $item->batch_number ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $item->expiry_date?->format('d M Y') ?? '-' }}</td>
                    </tr>
                @endforeach
            </x-table>
        </x-card>
    </div>

    @if ($receipt->notes)
        <x-card title="Notes" class="mt-6">
            <p class="text-sm text-slate-600 dark:text-slate-300">{{ $receipt->notes }}</p>
        </x-card>
    @endif
</div>
