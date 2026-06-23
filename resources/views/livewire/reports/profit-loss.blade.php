<?php

use App\Models\Branch;
use App\Services\FinancialReportService;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['branch_id' => '', 'date_from' => '', 'date_to' => '']);
mount(function () { $this->date_from = now()->startOfMonth()->toDateString(); $this->date_to = today()->toDateString(); });

?>

<div>
    <x-page-header title="Profit & Loss" description="Revenue, COGS, gross profit, expenses, and net profit." :breadcrumbs="['Dashboard' => route('dashboard'), 'Reports' => null, 'Profit & Loss' => null]"><div class="flex gap-2"><button onclick="window.print()" class="rounded-lg border px-4 py-2 text-sm font-bold">Print</button><button class="rounded-lg border px-4 py-2 text-sm font-bold">Export PDF</button><button class="rounded-lg border px-4 py-2 text-sm font-bold">Export Excel</button></div></x-page-header>
    @php $report = app(FinancialReportService::class)->profitLoss($branch_id ? (int) $branch_id : null, $date_from, $date_to); @endphp
    <x-card><div class="grid gap-3 md:grid-cols-3"><select wire:model.live="branch_id" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><option value="">All branches</option>@foreach (Branch::orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select><input wire:model.live="date_from" type="date" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><input wire:model.live="date_to" type="date" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"></div></x-card>
    <div class="mt-4 grid gap-4 sm:grid-cols-5"><x-card><p class="text-sm text-slate-500">Revenue</p><p class="text-xl font-black">TZS {{ number_format($report['revenue'], 2) }}</p></x-card><x-card><p class="text-sm text-slate-500">COGS</p><p class="text-xl font-black">TZS {{ number_format($report['cogs'], 2) }}</p></x-card><x-card><p class="text-sm text-slate-500">Gross Profit</p><p class="text-xl font-black">TZS {{ number_format($report['gross_profit'], 2) }}</p></x-card><x-card><p class="text-sm text-slate-500">Expenses</p><p class="text-xl font-black">TZS {{ number_format($report['expenses'], 2) }}</p></x-card><x-card><p class="text-sm text-slate-500">Net Profit</p><p class="text-xl font-black {{ $report['net_profit'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">TZS {{ number_format($report['net_profit'], 2) }}</p></x-card></div>
    <x-card title="Statement" class="mt-4"><x-table :headers="['Metric', 'Amount']"><tr><td class="px-4 py-3">Sales Revenue</td><td class="px-4 py-3 text-right">TZS {{ number_format($report['revenue'], 2) }}</td></tr><tr><td class="px-4 py-3">Cost of Goods Sold</td><td class="px-4 py-3 text-right">TZS {{ number_format($report['cogs'], 2) }}</td></tr><tr><td class="px-4 py-3 font-bold">Gross Profit</td><td class="px-4 py-3 text-right font-bold">TZS {{ number_format($report['gross_profit'], 2) }}</td></tr><tr><td class="px-4 py-3">Expenses</td><td class="px-4 py-3 text-right">TZS {{ number_format($report['expenses'], 2) }}</td></tr><tr><td class="px-4 py-3 font-black">Net Profit</td><td class="px-4 py-3 text-right font-black">TZS {{ number_format($report['net_profit'], 2) }}</td></tr></x-table></x-card>
</div>
