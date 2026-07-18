<?php

use App\Models\Branch;
use App\Services\FinancialReportService;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['branch_id' => '', 'date_from' => '', 'date_to' => '']);
mount(function () {
    $this->branch_id = request('branch_id', $this->branch_id);
    $this->date_from = request('date_from', now()->startOfMonth()->toDateString());
    $this->date_to = request('date_to', today()->toDateString());
});

?>

<div>
    <x-page-header title="Profit & Loss" description="Revenue, COGS, gross profit, expenses, and net profit." :breadcrumbs="['Dashboard' => route('dashboard'), 'Reports' => null, 'Profit & Loss' => null]"><x-export-actions export="reports.profit-loss" :params="compact('branch_id', 'date_from', 'date_to')" /></x-page-header>
    @php $report = app(FinancialReportService::class)->profitLoss($branch_id ? (int) $branch_id : null, $date_from, $date_to); @endphp
    <x-card><div class="grid gap-3 md:grid-cols-3"><select wire:model.live="branch_id" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><option value="">All branches</option>@foreach (Branch::orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select><input wire:model.live="date_from" type="date" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><input wire:model.live="date_to" type="date" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"></div></x-card>
    <div class="mt-4 grid gap-4 sm:grid-cols-5"><x-card><p class="text-sm text-slate-500">Revenue</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($report['revenue']) }}</p></x-card><x-card><p class="text-sm text-slate-500">COGS</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($report['cogs']) }}</p></x-card><x-card><p class="text-sm text-slate-500">Gross Profit</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($report['gross_profit']) }}</p></x-card><x-card><p class="text-sm text-slate-500">Expenses</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($report['expenses']) }}</p></x-card><x-card><p class="text-sm text-slate-500">Net Profit</p><p class="text-xl font-black {{ $report['net_profit'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">TZS {{ \App\Support\NumberFormatter::money($report['net_profit']) }}</p></x-card></div>
    <x-card title="Statement" class="mt-4"><x-table :headers="['Metric', 'Amount']"><tr><td class="px-4 py-3">Sales Revenue</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($report['revenue']) }}</td></tr><tr><td class="px-4 py-3">Cost of Goods Sold</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($report['cogs']) }}</td></tr><tr><td class="px-4 py-3 font-bold">Gross Profit</td><td class="px-4 py-3 text-right font-bold">TZS {{ \App\Support\NumberFormatter::money($report['gross_profit']) }}</td></tr><tr><td class="px-4 py-3">Expenses</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($report['expenses']) }}</td></tr><tr><td class="px-4 py-3 font-black">Net Profit</td><td class="px-4 py-3 text-right font-black">TZS {{ \App\Support\NumberFormatter::money($report['net_profit']) }}</td></tr></x-table></x-card>
</div>
