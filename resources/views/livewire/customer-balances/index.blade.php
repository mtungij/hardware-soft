<?php

use App\Models\Customer;
use App\Services\AccountingService;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);

state(['search' => '']);

?>

<div>
    <x-page-header title="Customer Balances" description="Track outstanding balances, statements, and receipts." :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Balances' => null]">
        <a href="{{ route('reports.customer-payments') }}" wire:navigate class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-bold dark:border-slate-700">Payments Report</a>
        <a href="{{ route('customer-payments.create') }}" wire:navigate class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">Record Payment</a>
    </x-page-header>

    @php
        $accounting = app(AccountingService::class);
        $customers = Customer::query()
            ->where(fn ($query) => $query->where('is_system_customer', false)->orWhereNull('is_system_customer'))
            ->when($search, fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
            ->withSum(['sales as total_credit_sales' => fn ($query) => $query->where('status', 'completed')], 'total_amount')
            ->withSum('payments as total_customer_payments', 'amount')
            ->withMax(['sales as last_credit_sale_date' => fn ($query) => $query->where('status', 'completed')], 'sale_date')
            ->withMax('payments as last_payment_date', 'payment_date')
            ->orderBy('name')
            ->paginate(12);
        $balanceCustomers = Customer::query()->where(fn ($query) => $query->where('is_system_customer', false)->orWhereNull('is_system_customer'))->get();
        $totalBalance = $balanceCustomers->sum(fn ($customer) => $accounting->customerBalance($customer));
        $customersWithBalance = $balanceCustomers->filter(fn ($customer) => $accounting->customerBalance($customer) > 0)->count();
    @endphp

    <div class="grid gap-4 sm:grid-cols-3">
        <x-card><p class="text-sm text-slate-500">Outstanding Balance</p><p class="mt-2 text-2xl font-black text-red-600">TZS {{ \App\Support\NumberFormatter::money($totalBalance) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">Customers With Balance</p><p class="mt-2 text-2xl font-black">{{ $customersWithBalance }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">Overdue Placeholder</p><p class="mt-2 text-2xl font-black text-amber-600">TZS {{ \App\Support\NumberFormatter::money(0) }}</p></x-card>
    </div>

    <x-card class="mt-6">
        <input wire:model.live.debounce.300ms="search" class="mb-4 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="Search customers">
        <x-table :headers="['Customer Name', 'Phone', 'Total Credit Sales', 'Total Payments', 'Outstanding Balance', 'Last Credit Sale', 'Last Payment', 'Actions']">
            @foreach ($customers as $customer)
                @php $balance = $accounting->customerBalance($customer); @endphp
                <tr>
                    <td class="px-4 py-3"><a href="{{ route('customer-balances.show', $customer) }}" wire:navigate class="font-bold text-build-orange">{{ $customer->name }}</a></td>
                    <td class="px-4 py-3">{{ $customer->phone ?: '-' }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($customer->total_credit_sales) }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($customer->total_customer_payments) }}</td>
                    <td class="px-4 py-3 text-right font-bold text-red-600">TZS {{ \App\Support\NumberFormatter::money($balance) }}</td>
                    <td class="px-4 py-3">{{ $customer->last_credit_sale_date ? \Illuminate\Support\Carbon::parse($customer->last_credit_sale_date)->format('M d, Y') : '-' }}</td>
                    <td class="px-4 py-3">{{ $customer->last_payment_date ? \Illuminate\Support\Carbon::parse($customer->last_payment_date)->format('M d, Y') : '-' }}</td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap justify-end gap-2">
                            <a href="{{ route('customer-balances.show', $customer) }}" wire:navigate class="text-sm font-bold text-build-orange">View Statement</a>
                            <a href="{{ route('customer-balances.show', ['customer' => $customer, 'tab' => 'purchases']) }}" wire:navigate class="text-sm font-bold text-cyan-700 dark:text-cyan-300">Credit Purchases</a>
                            <a href="{{ route('customer-payments.create', ['customer_id' => $customer->id]) }}" wire:navigate class="text-sm font-bold text-emerald-700 dark:text-emerald-300">Record Payment</a>
                            <a href="{{ route('customer-balances.show', ['customer' => $customer, 'print' => 1]) }}" class="text-sm font-bold text-slate-700 dark:text-slate-200">Print Statement</a>
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-table>
        <div class="mt-4">{{ $customers->links() }}</div>
    </x-card>
</div>
