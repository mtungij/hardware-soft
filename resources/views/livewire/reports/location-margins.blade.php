<?php

use App\Services\FinancialReportService;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['branch_id' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''), 'date_from' => '', 'date_to' => '']);
mount(function () {
    $this->date_from = now()->startOfMonth()->toDateString();
    $this->date_to = today()->toDateString();
});

?>

<div>
    <x-page-header title="Location Margins" description="Internal value is informational. Company profit uses customer revenue and original company inventory cost." :breadcrumbs="['Dashboard' => route('dashboard'), 'Reports' => null, 'Location Margins' => null]" />
    @php
        $reports = app(FinancialReportService::class);
        $branches = $reports->valuationBranches();
        $rows = collect($reports->locationMargins($branch_id ? (int) $branch_id : null, $date_from, $date_to));
        $companyProfit = auth()->user()->can('reports.profit')
            ? $reports->profitLoss($branch_id ? (int) $branch_id : null, $date_from, $date_to) : null;
    @endphp
    <x-card><div class="grid gap-3 md:grid-cols-3">
        <select wire:model.live="branch_id" class="rounded-lg border px-3 py-2 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())><option value="">All authorized branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
        <input wire:model.live="date_from" type="date" class="rounded-lg border px-3 py-2 dark:bg-navy-950">
        <input wire:model.live="date_to" type="date" class="rounded-lg border px-3 py-2 dark:bg-navy-950">
    </div></x-card>
    @if($companyProfit)
        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <x-card><p class="text-sm text-slate-500">External Customer Revenue</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($companyProfit['revenue']) }}</p></x-card>
            <x-card><p class="text-sm text-slate-500">Original Company COGS</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($companyProfit['cogs']) }}</p></x-card>
            <x-card><p class="text-sm text-slate-500">Consolidated Gross Profit</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($companyProfit['gross_profit']) }}</p></x-card>
        </div>
    @endif
    <x-card class="mt-4">
        <x-table :headers="['Location', 'External revenue', 'Location COGS', 'Outlet margin', 'Internal sales out', 'Source internal cost', 'Internal purchases in', 'Source internal margin', 'Company stock value', 'Location commercial value']">
            @foreach($rows as $row)
                <tr>
                    <td class="px-4 py-3 font-bold">{{ $row['location'] }}</td>
                    @foreach(['external_revenue', 'location_cogs', 'outlet_margin', 'internal_sales_out'] as $column)
                        <td class="px-4 py-3 text-right">{{ \App\Support\NumberFormatter::money($row[$column]) }}</td>
                    @endforeach
                    <td class="px-4 py-3 text-right">{{ \App\Support\NumberFormatter::money($row['internal_sales_out'] - $row['source_internal_margin']) }}</td>
                    @foreach(['internal_purchases_in', 'source_internal_margin', 'company_stock_value', 'location_commercial_value'] as $column)
                        <td class="px-4 py-3 text-right">{{ \App\Support\NumberFormatter::money($row[$column]) }}</td>
                    @endforeach
                </tr>
            @endforeach
        </x-table>
        <p class="mt-3 text-xs text-slate-500">Internal source and outlet margins are analytical views. They are excluded from consolidated company gross profit.</p>
    </x-card>
</div>
