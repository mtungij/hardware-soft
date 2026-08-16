<?php

use App\Models\Branch;
use App\Models\CustomerMaterialAccount;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);
state(['search' => '', 'status' => '', 'branch_id' => '']);
?>

<div>
    <x-page-header title="Customer Material Accounts" description="Funded construction-material plans, deposits, collections, and balances." :breadcrumbs="['Dashboard' => route('dashboard'), 'Material Accounts' => null]">
        @can('customer_material_accounts.reports')<a href="{{ route('customer-material-accounts.reports') }}" wire:navigate class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-bold dark:border-slate-700">Reports</a>@endcan
        @can('customer_material_accounts.create')<a href="{{ route('customer-material-accounts.create') }}" wire:navigate class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white">New Material Account</a>@endcan
    </x-page-header>

    @php
        $accounts = CustomerMaterialAccount::query()->with(['customer', 'branch'])
            ->when($search, fn ($q) => $q->where(fn ($q) => $q->where('reference_number', 'like', "%{$search}%")->orWhere('project_name', 'like', "%{$search}%")->orWhereHas('customer', fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))))
            ->when($status, fn ($q) => $q->where('status', $status))->when($branch_id, fn ($q) => $q->where('branch_id', $branch_id))->latest()->paginate(15);
    @endphp

    <x-card>
        <div class="mb-4 grid gap-3 md:grid-cols-3">
            <input wire:model.live.debounce.300ms="search" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950" placeholder="Customer, phone, project or reference">
            <select wire:model.live="status" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"><option value="">All statuses</option>@foreach (CustomerMaterialAccount::STATUSES as $value)<option value="{{ $value }}">{{ str($value)->title() }}</option>@endforeach</select>
            <select wire:model.live="branch_id" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"><option value="">All branches</option>@foreach (Branch::orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
        </div>
        <x-table :headers="['Reference / Project', 'Customer', 'Branch', 'Project Value', 'Deposited', 'Issued', 'Available', 'Status']">
            @forelse ($accounts as $account)
                <tr>
                    <td class="px-4 py-3"><a class="font-bold text-build-orange" wire:navigate href="{{ route('customer-material-accounts.show', $account) }}">{{ $account->reference_number }}</a><p class="text-xs text-slate-500">{{ $account->project_name }}</p></td>
                    <td class="px-4 py-3 font-bold">{{ $account->customer->name }}<p class="text-xs font-normal text-slate-500">{{ $account->customer->phone }}</p></td>
                    <td class="px-4 py-3">{{ $account->branch->name }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($account->plannedValue()) }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($account->depositedAmount()) }}</td>
                    <td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($account->issuedValue()) }}</td>
                    <td class="px-4 py-3 text-right font-black text-emerald-600">TZS {{ \App\Support\NumberFormatter::money($account->availableFundedBalance()) }}</td>
                    <td class="px-4 py-3"><x-badge :color="match($account->status) { 'active' => 'green', 'draft' => 'yellow', 'cancelled' => 'red', default => 'gray' }">{{ str($account->status)->title() }}</x-badge></td>
                </tr>
            @empty<tr><td colspan="8" class="px-4 py-10 text-center text-slate-500">No customer material accounts found.</td></tr>@endforelse
        </x-table>
        <div class="mt-4">{{ $accounts->links() }}</div>
    </x-card>
</div>
