<?php

use App\Models\Customer;
use App\Services\AccountingService;

use function Livewire\Volt\layout;
use function Livewire\Volt\state;

layout('layouts.app');
state(['search' => '']);

?>

<div>
    <x-page-header title="Customer Balance Report" description="Customer outstanding balances and payment exposure." :breadcrumbs="['Dashboard' => route('dashboard'), 'Reports' => null, 'Customers' => null]"><x-export-actions export="reports.customers" :params="compact('search')" /></x-page-header>
    @php $accounting = app(AccountingService::class); $rows = Customer::query()->where(fn ($query) => $query->where('is_system_customer', false)->orWhereNull('is_system_customer'))->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))->get()->map(fn ($customer) => ['customer' => $customer, 'balance' => $accounting->customerBalance($customer)]); @endphp
    <x-card><input wire:model.live.debounce.300ms="search" class="w-full rounded-lg border px-3 py-2 text-sm dark:bg-navy-950" placeholder="Search customer"></x-card>
    <div class="mt-4 grid gap-4 sm:grid-cols-2"><x-card><p class="text-sm text-slate-500">Customers</p><p class="text-2xl font-black">{{ $rows->count() }}</p></x-card><x-card><p class="text-sm text-slate-500">Outstanding</p><p class="text-2xl font-black text-red-600">TZS {{ \App\Support\NumberFormatter::money($rows->sum('balance')) }}</p></x-card></div>
    <x-card class="mt-4"><x-table :headers="['Customer', 'Phone', 'Outstanding', 'Overdue']">@foreach ($rows as $row)<tr><td class="px-4 py-3 font-bold">{{ $row['customer']->name }}</td><td class="px-4 py-3">{{ $row['customer']->phone }}</td><td class="px-4 py-3 text-right font-bold">TZS {{ \App\Support\NumberFormatter::money($row['balance']) }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money(0) }}</td></tr>@endforeach</x-table></x-card>
</div>
