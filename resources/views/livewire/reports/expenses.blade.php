<?php

use App\Models\Branch;
use App\Models\Expense;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['branch_id' => '', 'date_from' => '', 'date_to' => '', 'search' => '']);
mount(function () { $this->date_from = now()->startOfMonth()->toDateString(); $this->date_to = today()->toDateString(); });

?>

<div>
    <x-page-header title="Expense Report" description="Operating costs by branch, category, and payment method." :breadcrumbs="['Dashboard' => route('dashboard'), 'Reports' => null, 'Expenses' => null]"><div class="flex gap-2"><button onclick="window.print()" class="rounded-lg border px-4 py-2 text-sm font-bold">Print</button><button class="rounded-lg border px-4 py-2 text-sm font-bold">Export PDF</button><button class="rounded-lg border px-4 py-2 text-sm font-bold">Export Excel</button></div></x-page-header>
    @php $rows = Expense::with(['branch','category','paidBy'])->whereBetween('expense_date', [$date_from, $date_to])->when($branch_id, fn ($q) => $q->where('branch_id', $branch_id))->when($search, fn ($q) => $q->whereHas('category', fn ($c) => $c->where('name', 'like', "%{$search}%")))->latest()->get(); @endphp
    <x-card><div class="grid gap-3 md:grid-cols-4"><select wire:model.live="branch_id" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><option value="">All branches</option>@foreach (Branch::orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select><input wire:model.live="date_from" type="date" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><input wire:model.live="date_to" type="date" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><input wire:model.live.debounce.300ms="search" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950" placeholder="Search"></div></x-card>
    <div class="mt-4 grid gap-4 sm:grid-cols-3"><x-card><p class="text-sm text-slate-500">Entries</p><p class="text-2xl font-black">{{ $rows->count() }}</p></x-card><x-card><p class="text-sm text-slate-500">Expenses</p><p class="text-2xl font-black">TZS {{ number_format((float) $rows->sum('amount'), 2) }}</p></x-card><x-card><p class="text-sm text-slate-500">Cash Expenses</p><p class="text-2xl font-black">TZS {{ number_format((float) $rows->where('payment_method', 'cash')->sum('amount'), 2) }}</p></x-card></div>
    <x-card class="mt-4"><x-table :headers="['Date', 'Category', 'Branch', 'Method', 'Paid By', 'Amount']">@foreach ($rows as $expense)<tr><td class="px-4 py-3">{{ $expense->expense_date?->format('M d, Y') }}</td><td class="px-4 py-3 font-bold">{{ $expense->category?->name }}</td><td class="px-4 py-3">{{ $expense->branch?->name }}</td><td class="px-4 py-3">{{ str($expense->payment_method)->replace('_', ' ')->title() }}</td><td class="px-4 py-3">{{ $expense->paidBy?->name }}</td><td class="px-4 py-3 text-right">TZS {{ number_format((float) $expense->amount, 2) }}</td></tr>@endforeach</x-table></x-card>
</div>
