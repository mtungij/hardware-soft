<?php

use App\Models\Sale;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['sale' => null]);

mount(function (Sale $sale) {
    $this->sale = $sale->load(['branch', 'customer', 'createdBy', 'cancelledBy', 'items.product', 'items.stockLocation', 'payments.receivedBy']);
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
                <div class="rounded-lg bg-slate-50 p-4 dark:bg-white/5"><p class="text-xs text-slate-500">Subtotal</p><p class="text-lg font-black">TZS {{ number_format((float) $sale->subtotal, 2) }}</p></div>
                <div class="rounded-lg bg-slate-50 p-4 dark:bg-white/5"><p class="text-xs text-slate-500">Discount</p><p class="text-lg font-black">TZS {{ number_format((float) $sale->discount_amount, 2) }}</p></div>
                <div class="rounded-lg bg-slate-50 p-4 dark:bg-white/5"><p class="text-xs text-slate-500">Tax</p><p class="text-lg font-black">TZS {{ number_format((float) $sale->tax_amount, 2) }}</p></div>
                <div class="rounded-lg bg-slate-50 p-4 dark:bg-white/5"><p class="text-xs text-slate-500">Paid</p><p class="text-lg font-black">TZS {{ number_format((float) $sale->paid_amount, 2) }}</p></div>
                <div class="rounded-lg bg-navy-900 p-4 text-white"><p class="text-xs text-slate-300">Balance</p><p class="text-lg font-black">TZS {{ number_format((float) $sale->balance_amount, 2) }}</p></div>
            </div>
        </x-card>
    </div>

    <x-card title="Items" class="mt-6">
        <x-table>
            <x-slot:head>
                <tr>
                    <th class="px-4 py-3 text-left">Product</th>
                    <th class="px-4 py-3 text-left">Stock Source</th>
                    <th class="px-4 py-3 text-right">Qty</th>
                    <th class="px-4 py-3 text-right">Price</th>
                    <th class="px-4 py-3 text-right">Discount</th>
                    <th class="px-4 py-3 text-right">Tax</th>
                    <th class="px-4 py-3 text-right">Total</th>
                </tr>
            </x-slot:head>
            @foreach ($sale->items as $item)
                <tr class="border-t border-slate-100 dark:border-slate-800">
                    <td class="px-4 py-3 font-bold">{{ $item->product?->name }}</td>
                    <td class="px-4 py-3">{{ $item->stockLocation?->name }}</td>
                    <td class="px-4 py-3 text-right">{{ number_format((float) $item->quantity, 2) }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ number_format((float) $item->unit_price, 2) }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ number_format((float) $item->discount_amount, 2) }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ number_format((float) $item->tax_amount, 2) }}</td>
                    <td class="px-4 py-3 text-right font-bold">TZS {{ number_format((float) $item->line_total, 2) }}</td>
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
                    <td class="px-4 py-3 text-right font-bold">TZS {{ number_format((float) $payment->amount, 2) }}</td>
                </tr>
            @endforeach
        </x-table>
    </x-card>
</div>
