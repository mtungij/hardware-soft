<?php

use App\Models\Sale;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['sale' => null]);

mount(function (Sale $sale) {
    $this->sale = $sale->load(['branch', 'customer', 'createdBy', 'cancelledBy', 'items.product', 'items.stockLocation', 'items.sellingUnit', 'items.baseUnit', 'payments.receivedBy']);
});

?>

<div>
    <x-page-header title="Sale Details" :description="$sale->sale_number" :breadcrumbs="['Dashboard' => route('dashboard'), 'Sales' => route('sales.index'), $sale->sale_number => null]">
        <div class="flex gap-2">
            <a href="{{ route('sales.receipt', $sale) }}" wire:navigate class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-bold dark:border-slate-700">Receipt</a>
            @if ($sale->status === 'completed' && (float) $sale->balance_amount > 0)
                <a href="{{ route('sales.payments', $sale) }}" wire:navigate class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">Receive Payment</a>
            @endif
        </div>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-card title="Sale Summary" class="lg:col-span-1">
            <div class="space-y-3 text-sm">
                <div class="flex justify-between"><span class="text-slate-500">Date</span><span class="font-bold">{{ $sale->sale_date?->format('M d, Y') }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">Customer</span><span class="font-bold">{{ $sale->customer?->name ?? 'Walk-in Customer' }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">Sale Type</span><span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $sale->saleType() === 'wholesale' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-blue-100 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' }}">{{ $sale->saleTypeLabel() }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">Branch</span><span class="font-bold">{{ $sale->branch?->name }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">Cashier</span><span class="font-bold">{{ $sale->createdBy?->name }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">Status</span><span class="font-bold">{{ ucfirst($sale->status) }} / {{ ucfirst($sale->payment_status) }}</span></div>
                @if ($sale->cancelled_at)
                    <div class="rounded-lg bg-red-50 p-3 text-red-700 dark:bg-red-500/10 dark:text-red-300">Cancelled by {{ $sale->cancelledBy?->name }} on {{ $sale->cancelled_at->format('M d, Y H:i') }}</div>
                @endif
            </div>
        </x-card>

        <x-card title="Totals" class="lg:col-span-2">
            <div class="grid gap-4 sm:grid-cols-5">
                <div class="rounded-lg bg-slate-50 p-4 dark:bg-white/5"><p class="text-xs text-slate-500">Subtotal</p><p class="text-lg font-black">TZS {{ \App\Support\NumberFormatter::money($sale->subtotal) }}</p></div>
                <div class="rounded-lg bg-slate-50 p-4 dark:bg-white/5"><p class="text-xs text-slate-500">Discount</p><p class="text-lg font-black">TZS {{ \App\Support\NumberFormatter::money($sale->discount_amount) }}</p></div>
                <div class="rounded-lg bg-slate-50 p-4 dark:bg-white/5"><p class="text-xs text-slate-500">Tax</p><p class="text-lg font-black">TZS {{ \App\Support\NumberFormatter::money($sale->tax_amount) }}</p></div>
                <div class="rounded-lg bg-slate-50 p-4 dark:bg-white/5"><p class="text-xs text-slate-500">Paid</p><p class="text-lg font-black">TZS {{ \App\Support\NumberFormatter::money($sale->paid_amount) }}</p></div>
                <div class="rounded-lg bg-navy-900 p-4 text-white"><p class="text-xs text-slate-300">Balance</p><p class="text-lg font-black">TZS {{ \App\Support\NumberFormatter::money($sale->balance_amount) }}</p></div>
            </div>
        </x-card>
    </div>

    <x-card title="Items" class="mt-6">
        <x-table>
            <x-slot:head>
                <tr>
                    <th class="px-4 py-3 text-left">Product</th>
                    <th class="px-4 py-3 text-left">Stock Source</th>
                    <th class="px-4 py-3 text-left">Sale Type</th>
                    <th class="px-4 py-3 text-right">Qty</th>
                    <th class="px-4 py-3 text-right">Base Qty</th>
                    <th class="px-4 py-3 text-right">Conversion</th>
                    <th class="px-4 py-3 text-right">Price</th>
                    <th class="px-4 py-3 text-right">Discount/Unit</th>
                    <th class="px-4 py-3 text-right">Net Unit</th>
                    <th class="px-4 py-3 text-right">Line Discount</th>
                    <th class="px-4 py-3 text-right">Tax</th>
                    <th class="px-4 py-3 text-right">Total</th>
                </tr>
            </x-slot:head>
            @foreach ($sale->items as $item)
                @php
                    $discountPerUnit = (float) ($item->discount_per_unit ?? 0);
                    $netUnitPrice = (float) ($item->net_unit_price ?? ((float) $item->unit_price - $discountPerUnit));
                    $lineDiscount = (float) ($item->discount_total ?? $item->discount_amount);
                @endphp
                <tr class="border-t border-slate-100 dark:border-slate-800">
                    <td class="px-4 py-3 font-bold">
                        {{ $item->product?->displayName() }}
                        @if ($item->sizeLabel())
                            <p class="text-xs font-bold text-cyan-700 dark:text-cyan-200">Size: {{ $item->sizeLabel() }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-3">{{ $item->sold_from_label ?: ($item->stockLocation ? \App\Support\InventorySettings::stockLocationLabel($item->stockLocation) : '-') }}</td>
                    <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $item->sale_type === 'wholesale' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-blue-100 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' }}">{{ str($item->sale_type ?? 'retail')->title() }}</span></td>
                    <td class="px-4 py-3 text-right">{{ \App\Support\NumberFormatter::quantity($item->quantity) }} {{ $item->sellingUnit?->short_name }}</td>
                    <td class="px-4 py-3 text-right">{{ \App\Support\NumberFormatter::quantity($item->base_quantity ?: $item->quantity) }} {{ $item->baseUnit?->short_name }}</td>
                    <td class="px-4 py-3 text-right">1 {{ $item->baseUnit?->short_name }} = {{ \App\Support\NumberFormatter::quantity($item->conversion_factor ?: 1) }} {{ $item->sellingUnit?->short_name }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($item->unit_price) }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($discountPerUnit) }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($netUnitPrice) }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($lineDiscount) }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($item->tax_amount) }}</td>
                    <td class="px-4 py-3 text-right font-bold">TZS {{ \App\Support\NumberFormatter::money($item->line_total) }}</td>
                </tr>
            @endforeach
        </x-table>
    </x-card>

    <x-card title="Payments" class="mt-6">
        <x-table>
            <x-slot:head>
                <tr>
                    <th class="px-4 py-3 text-left">Date</th>
                    <th class="px-4 py-3 text-left">Method</th>
                    <th class="px-4 py-3 text-left">Reference</th>
                    <th class="px-4 py-3 text-left">Received By</th>
                    <th class="px-4 py-3 text-right">Amount</th>
                </tr>
            </x-slot:head>
            @foreach ($sale->payments as $payment)
                <tr class="border-t border-slate-100 dark:border-slate-800">
                    <td class="px-4 py-3">{{ $payment->payment_date?->format('M d, Y') }}</td>
                    <td class="px-4 py-3">{{ str($payment->payment_method)->replace('_', ' ')->title() }}</td>
                    <td class="px-4 py-3">{{ $payment->reference_number ?: '-' }}</td>
                    <td class="px-4 py-3">{{ $payment->receivedBy?->name }}</td>
                    <td class="px-4 py-3 text-right font-bold">TZS {{ \App\Support\NumberFormatter::money($payment->amount) }}</td>
                </tr>
            @endforeach
        </x-table>
    </x-card>
</div>
