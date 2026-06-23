<?php

use App\Models\Customer;
use App\Services\AccountingService;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['customer' => null]);

mount(function (Customer $customer) {
    $this->customer = $customer->load(['sales', 'payments.receivedBy']);
});

?>

<div>
    @php $balance = app(AccountingService::class)->customerBalance($customer); @endphp
    <x-page-header title="Customer Statement" :description="$customer->name" :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Balances' => route('customer-balances.index'), $customer->name => null]">
        <a href="{{ route('customer-payments.create', ['customer_id' => $customer->id]) }}" wire:navigate class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">Record Payment</a>
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-4">
        <x-card><p class="text-sm text-slate-500">Opening Balance</p><p class="mt-2 text-xl font-black">TZS {{ number_format((float) $customer->opening_balance, 2) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">Credit Limit</p><p class="mt-2 text-xl font-black">TZS {{ number_format((float) $customer->credit_limit, 2) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">Outstanding</p><p class="mt-2 text-xl font-black text-red-600">TZS {{ number_format($balance, 2) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">Overdue</p><p class="mt-2 text-xl font-black text-amber-600">TZS 0.00</p></x-card>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-card title="Credit Sales">
            <x-table :headers="['Date', 'Sale', 'Total', 'Balance']">
                @foreach ($customer->sales()->latest()->get() as $sale)
                    <tr><td class="px-4 py-3">{{ $sale->sale_date?->format('M d, Y') }}</td><td class="px-4 py-3 font-bold">{{ $sale->sale_number }}</td><td class="px-4 py-3 text-right">TZS {{ number_format((float) $sale->total_amount, 2) }}</td><td class="px-4 py-3 text-right">TZS {{ number_format((float) $sale->balance_amount, 2) }}</td></tr>
                @endforeach
            </x-table>
        </x-card>
        <x-card title="Payment History">
            <x-table :headers="['Date', 'Method', 'Reference', 'Amount']">
                @foreach ($customer->payments()->latest()->get() as $payment)
                    <tr><td class="px-4 py-3">{{ $payment->payment_date?->format('M d, Y') }}</td><td class="px-4 py-3">{{ str($payment->payment_method)->replace('_', ' ')->title() }}</td><td class="px-4 py-3">{{ $payment->reference_number ?: '-' }}</td><td class="px-4 py-3 text-right font-bold">TZS {{ number_format((float) $payment->amount, 2) }}</td></tr>
                @endforeach
            </x-table>
        </x-card>
    </div>
</div>
