<?php

use App\Models\Branch;
use App\Services\FinancialReportService;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['branch_id' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''), 'date_from' => '', 'date_to' => '']);
mount(function () {
    $this->branch_id = (string) (\App\Support\BranchAccess::resolve(auth()->user(), request('branch_id', $this->branch_id) ? (int) request('branch_id', $this->branch_id) : null) ?? '');
    $this->date_from = request('date_from', now()->startOfMonth()->toDateString());
    $this->date_to = request('date_to', today()->toDateString());
});

?>

<div>
    <x-page-header title="{{ __('application.profit_loss_4a12196f') }}" description="{{ __('application.revenue_cogs_gross_profit_expenses_and_net_profit_ebf4e6dd') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Reports' => null, 'Profit & Loss' => null]"><x-export-actions export="reports.profit-loss" :params="compact('branch_id', 'date_from', 'date_to')" /></x-page-header>
    @php $report = app(FinancialReportService::class)->profitLoss($branch_id ? (int) $branch_id : null, $date_from, $date_to); @endphp
    <x-card><div class="grid gap-3 md:grid-cols-3"><select wire:model.live="branch_id" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())><option value="">{{ __('application.all_branches_25c23746') }}</option>@foreach (Branch::orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select><input wire:model.live="date_from" type="date" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"><input wire:model.live="date_to" type="date" class="rounded-lg border px-3 py-2 text-sm dark:bg-navy-950"></div></x-card>
    <div class="mt-4 grid gap-4 sm:grid-cols-5"><x-card><p class="text-sm text-slate-500">{{ __('application.revenue_35cf82f9') }}</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($report['revenue']) }}</p></x-card><x-card><p class="text-sm text-slate-500">{{ __('application.cogs_7e86884c') }}</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($report['cogs']) }}</p></x-card><x-card><p class="text-sm text-slate-500">{{ __('application.gross_profit_11f53e51') }}</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($report['gross_profit']) }}</p></x-card><x-card><p class="text-sm text-slate-500">{{ __('application.expenses_8d437dbf') }}</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($report['expenses']) }}</p></x-card><x-card><p class="text-sm text-slate-500">{{ __('application.net_profit_8bebc63c') }}</p><p class="text-xl font-black {{ $report['net_profit'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">TZS {{ \App\Support\NumberFormatter::money($report['net_profit']) }}</p></x-card></div>
    <x-card title="{{ __('application.statement_a72ca256') }}" class="mt-4"><x-table :headers="[__('application.metric_b2bb7604'), __('application.amount_43dc8532')]"><tr><td class="px-4 py-3">{{ __('application.sales_revenue_238dca8b') }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($report['revenue']) }}</td></tr><tr><td class="px-4 py-3">{{ __('application.cost_of_goods_sold_54b3a1b7') }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($report['cogs']) }}</td></tr><tr><td class="px-4 py-3 font-bold">{{ __('application.gross_profit_11f53e51') }}</td><td class="px-4 py-3 text-right font-bold">TZS {{ \App\Support\NumberFormatter::money($report['gross_profit']) }}</td></tr><tr><td class="px-4 py-3">{{ __('application.expenses_8d437dbf') }}</td><td class="px-4 py-3 text-right">TZS {{ \App\Support\NumberFormatter::money($report['expenses']) }}</td></tr><tr><td class="px-4 py-3 font-black">{{ __('application.net_profit_8bebc63c') }}</td><td class="px-4 py-3 text-right font-black">TZS {{ \App\Support\NumberFormatter::money($report['net_profit']) }}</td></tr></x-table></x-card>
</div>
