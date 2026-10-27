<?php

use App\Support\ExpenseAccess;

use function Livewire\Volt\hydrate;
use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['branch_id' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''), 'date_from' => '', 'date_to' => '', 'search' => '']);
hydrate(fn () => ExpenseAccess::authorize(auth()->user(), 'view expenses'));
mount(function () { ExpenseAccess::authorize(auth()->user(), 'view expenses'); $this->date_from = now()->startOfMonth()->toDateString(); $this->date_to = today()->toDateString(); });

?>

<div>
    <x-page-header title="{{ __('application.expense_report_285d669e') }}" description="{{ __('application.operating_costs_by_branch_category_and_payment_method_65b51980') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Reports' => null, 'Expenses' => null]"><x-export-actions export="reports.expenses" :params="compact('branch_id', 'date_from', 'date_to', 'search')" /></x-page-header>
    @php $rows = ExpenseAccess::query(auth()->user())->with(['branch','category','paidBy'])->whereBetween('expense_date', [$date_from, $date_to])->when($branch_id, fn ($q) => $q->where('branch_id', $branch_id))->when($search, fn ($q) => $q->whereHas('category', fn ($c) => $c->where('name', 'like', "%{$search}%")))->latest()->get(); @endphp
    <x-card><div class="grid gap-3 md:grid-cols-4"><select wire:model.live="branch_id" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())><option value="">{{ __('application.all_branches_25c23746') }}</option>@foreach (\App\Support\BranchAccess::branches(auth()->user())->orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select><input wire:model.live="date_from" type="date" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><input wire:model.live="date_to" type="date" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><input wire:model.live.debounce.300ms="search" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950" placeholder="{{ __('application.search_bce06414') }}"></div></x-card>
    <div class="mt-4 grid gap-4 sm:grid-cols-3"><x-card><p class="text-sm text-slate-500">{{ __('application.entries_f056d0d5') }}</p><p class="text-2xl font-black">{{ $rows->count() }}</p></x-card><x-card><p class="text-sm text-slate-500">{{ __('application.expenses_8d437dbf') }}</p><p class="text-2xl font-black">TZS {{ \App\Support\NumberFormatter::money($rows->sum('amount')) }}</p></x-card><x-card><p class="text-sm text-slate-500">{{ __('application.cash_expenses_2fb0f14c') }}</p><p class="text-2xl font-black">TZS {{ \App\Support\NumberFormatter::money($rows->where('payment_method', 'cash')->sum('amount')) }}</p></x-card></div>
    <x-card class="mt-4"><x-table :headers="[__('application.date_eb9a4bc1'), __('application.category_a3c686e7'), __('application.branch_1627510b'), __('application.method_88306943'), __('application.paid_by_2e06e8e0'), __('application.amount_43dc8532')]">@foreach ($rows as $expense)<tr><td class="px-4 py-3">{{ $expense->expense_date?->locale(app()->getLocale())->translatedFormat('M d, Y') }}</td><td class="px-4 py-3 font-bold">{{ $expense->category?->name }}</td><td class="px-4 py-3">{{ $expense->branch?->name }}</td><td class="px-4 py-3">{{ $expense->paymentMethodLabel() }}</td><td class="px-4 py-3">{{ $expense->paidBy?->name }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($expense->amount) }}</td></tr>@endforeach</x-table></x-card>
</div>
