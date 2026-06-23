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
    <x-page-header title="Customer Balances" description="Track credit limits, outstanding balances, statements, and receipts." :breadcrumbs="['Dashboard' => route('dashboard'), 'Customer Balances' => null]">
        <a href="{{ route('customer-payments.create') }}" wire:navigate class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">Record Payment</a>
    </x-page-header>

    @php
        $accounting = app(AccountingService::class);
        $customers = Customer::query()
            ->when($search, fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
            ->withCount('sales')
            ->orderBy('name')
            ->paginate(12);
        $totalBalance = Customer::query()->get()->sum(fn ($customer) => $accounting->customerBalance($customer));
    @endphp

    <div class="grid gap-4 sm:grid-cols-3">
        <x-card><p class="text-sm text-slate-500">Outstanding Balance</p><p class="mt-2 text-2xl font-black text-red-600">TZS {{ number_format((float) $totalBalance, 2) }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">Credit Customers</p><p class="mt-2 text-2xl font-black">{{ Customer::where('credit_limit', '>', 0)->count() }}</p></x-card>
        <x-card><p class="text-sm text-slate-500">Overdue Placeholder</p><p class="mt-2 text-2xl font-black text-amber-600">TZS 0.00</p></x-card>
    </div>

    <x-card class="mt-6">
        <input wire:model.live.debounce.300ms="search" class="mb-4 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-white/5" placeholder="Search customers">
        <x-table :headers="['Customer', 'Credit Limit', 'Outstanding', 'Usage', 'Sales', 'Actions']">
            @foreach ($customers as $customer)
                @php $balance = $accounting->customerBalance($customer); @endphp
                <tr>
                    <td class="px-4 py-3"><p class="font-bold">{{ $customer->name }}</p><p class="text-xs text-slate-500">{{ $customer->phone }}</p></td>
                    <td class="px-4 py-3 text-right">TZS {{ number_format((float) $customer->credit_limit, 2) }}</td>
                    <td class="px-4 py-3 text-right font-bold">TZS {{ number_format($balance, 2) }}</td>
                    <td class="px-4 py-3">{{ (float) $customer->credit_limit > 0 ? number_format($balance / (float) $customer->credit_limit * 100, 1).'%' : 'Cash' }}</td>
                    <td class="px-4 py-3">{{ $customer->sales_count }}</td>
                    <td class="px-4 py-3 text-right"><a href="{{ route('customer-balances.show', $customer) }}" wire:navigate class="text-sm font-bold text-build-orange">Statement</a></td>
                </tr>
            @endforeach
        </x-table>
        <div class="mt-4">{{ $customers->links() }}</div>
    </x-card>
</div>
