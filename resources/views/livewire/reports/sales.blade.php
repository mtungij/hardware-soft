<?php

use App\Models\Branch;
use App\Models\Sale;
use Livewire\WithPagination;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app');
uses([WithPagination::class]);
state(['branch_id' => '', 'date_from' => '', 'date_to' => '', 'search' => '']);
mount(function () { $this->date_from = now()->startOfMonth()->toDateString(); $this->date_to = today()->toDateString(); });

?>

<div>
    <x-page-header title="Sales Report" description="Revenue, payment status, cashier, and customer sales analysis." :breadcrumbs="['Dashboard' => route('dashboard'), 'Reports' => null, 'Sales' => null]">
        <div class="flex gap-2"><button onclick="window.print()" class="rounded-lg border px-4 py-2 text-sm font-bold">Print</button><button class="rounded-lg border px-4 py-2 text-sm font-bold">Export PDF</button><button class="rounded-lg border px-4 py-2 text-sm font-bold">Export Excel</button></div>
    </x-page-header>
    @php
        $query = Sale::with(['branch', 'customer', 'createdBy'])->whereBetween('sale_date', [$date_from, $date_to])->when($branch_id, fn ($q) => $q->where('branch_id', $branch_id))->when($search, fn ($q) => $q->where('sale_number', 'like', "%{$search}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")));
        $summary = ['sales' => (clone $query)->count(), 'revenue' => (clone $query)->where('status', 'completed')->sum('total_amount'), 'paid' => (clone $query)->sum('paid_amount'), 'balance' => (clone $query)->sum('balance_amount')];
        $rows = $query->latest()->paginate(15);
    @endphp
    <x-card><div class="grid gap-3 md:grid-cols-4"><select wire:model.live="branch_id" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><option value="">All branches</option>@foreach (Branch::orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select><input wire:model.live="date_from" type="date" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><input wire:model.live="date_to" type="date" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><input wire:model.live.debounce.300ms="search" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950" placeholder="Search"></div></x-card>
    <div class="mt-4 grid gap-4 sm:grid-cols-4"><x-card><p class="text-sm text-slate-500">Sales</p><p class="text-2xl font-black">{{ $summary['sales'] }}</p></x-card><x-card><p class="text-sm text-slate-500">Revenue</p><p class="text-2xl font-black">TZS {{ number_format((float) $summary['revenue'], 2) }}</p></x-card><x-card><p class="text-sm text-slate-500">Paid</p><p class="text-2xl font-black">TZS {{ number_format((float) $summary['paid'], 2) }}</p></x-card><x-card><p class="text-sm text-slate-500">Balance</p><p class="text-2xl font-black">TZS {{ number_format((float) $summary['balance'], 2) }}</p></x-card></div>
    <x-card class="mt-4"><x-table :headers="['Date', 'Sale', 'Customer', 'Branch', 'Total', 'Paid', 'Balance']">@foreach ($rows as $sale)<tr><td class="px-4 py-3">{{ $sale->sale_date?->format('M d, Y') }}</td><td class="px-4 py-3 font-bold">{{ $sale->sale_number }}</td><td class="px-4 py-3">{{ $sale->customer?->name ?? 'Walk-in' }}</td><td class="px-4 py-3">{{ $sale->branch?->name }}</td><td class="px-4 py-3 text-right">TZS {{ number_format((float) $sale->total_amount, 2) }}</td><td class="px-4 py-3 text-right">TZS {{ number_format((float) $sale->paid_amount, 2) }}</td><td class="px-4 py-3 text-right">TZS {{ number_format((float) $sale->balance_amount, 2) }}</td></tr>@endforeach</x-table><div class="mt-4">{{ $rows->links() }}</div></x-card>
</div>
