<?php

use App\Models\Sale;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.customer');

state(['sale' => null]);

mount(function (Sale $sale) {
    abort_unless($sale->customer_id === auth('customer')->user()->customer_id, 403);
    $this->sale = $sale->load(['items.product', 'payments']);
});
$paymentStatusLabel = fn (string $status) => [
    'paid' => __('messages.status.paid'),
    'partial' => __('messages.status.partial'),
    'unpaid' => __('messages.status.unpaid'),
][$status] ?? str($status)->replace('_', ' ')->title();

?>

<div>
    <x-page-header :title="__('messages.debts.details')" :description="$sale->sale_number" :breadcrumbs="[__('messages.customer_portal') => route('customer.dashboard'), __('messages.debts.title') => route('customer.debts.index'), $sale->sale_number => null]">
        @if ((float) $sale->balance_amount > 0)
            <a href="{{ route('customer.receipts.create', ['sale_id' => $sale->id]) }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2 text-sm font-black text-white">{{ __('messages.receipts.upload') }}</a>
        @endif
    </x-page-header>
    <div class="grid gap-4 sm:grid-cols-4">
        <x-card><p class="text-sm text-slate-500">{{ __('messages.debts.total_amount') }}</p><p class="mt-2 text-xl font-black">TZS {{ number_format((float) $sale->total_amount, 2) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">{{ __('messages.debts.paid_amount') }}</p><p class="mt-2 text-xl font-black text-emerald-600">TZS {{ number_format((float) $sale->paid_amount, 2) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">{{ __('messages.debts.balance') }}</p><p class="mt-2 text-xl font-black text-red-600">TZS {{ number_format((float) $sale->balance_amount, 2) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">{{ __('messages.debts.status') }}</p><p class="mt-2"><span class="rounded-full px-2.5 py-1 text-xs font-black {{ $sale->payment_status === 'paid' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200' : ($sale->payment_status === 'partial' ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200' : 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-200') }}">{{ $this->paymentStatusLabel($sale->payment_status) }}</span></p></x-card>
    </div>
    <x-card :title="__('messages.debts.items')" class="mt-6">
        <x-table :headers="[__('messages.debts.product'), __('messages.debts.quantity'), __('messages.debts.price'), __('messages.table.total')]">
            @foreach ($sale->items as $item)
                <tr><td class="px-4 py-3 font-bold">{{ $item->product?->name }}</td><td class="px-4 py-3 text-right">{{ number_format((float) $item->quantity, 2) }}</td><td class="px-4 py-3 text-right">TZS {{ number_format((float) $item->unit_price, 2) }}</td><td class="px-4 py-3 text-right font-bold">TZS {{ number_format((float) $item->line_total, 2) }}</td></tr>
            @endforeach
        </x-table>
    </x-card>
</div>
