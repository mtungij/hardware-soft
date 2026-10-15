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
    <x-page-header title="{{ __('application.location_margins_e070d51f') }}" description="{{ __('application.internal_value_is_informational_company_profit_uses_customer_revenue_and_or_2917eadb') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Reports' => null, 'Location Margins' => null]" />
    @php
        $reports = app(FinancialReportService::class);
        $branches = $reports->valuationBranches();
        $rows = collect($reports->locationMargins($branch_id ? (int) $branch_id : null, $date_from, $date_to));
        $companyProfit = auth()->user()->can('reports.profit')
            ? $reports->profitLoss($branch_id ? (int) $branch_id : null, $date_from, $date_to) : null;
    @endphp
    <x-card><div class="grid gap-3 md:grid-cols-3">
        <select wire:model.live="branch_id" class="rounded-lg border px-3 py-2 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())><option value="">{{ __('application.all_authorized_branches_6c5afd0a') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
        <input wire:model.live="date_from" type="date" class="rounded-lg border px-3 py-2 dark:bg-navy-950">
        <input wire:model.live="date_to" type="date" class="rounded-lg border px-3 py-2 dark:bg-navy-950">
    </div></x-card>
    @if($companyProfit)
        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <x-card><p class="text-sm text-slate-500">{{ __('application.external_customer_revenue_e8d34f47') }}</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($companyProfit['revenue']) }}</p></x-card>
            <x-card><p class="text-sm text-slate-500">{{ __('application.original_company_cogs_6b990e9d') }}</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($companyProfit['cogs']) }}</p></x-card>
            <x-card><p class="text-sm text-slate-500">{{ __('application.consolidated_gross_profit_d8cc9f4b') }}</p><p class="text-xl font-black">TZS {{ \App\Support\NumberFormatter::money($companyProfit['gross_profit']) }}</p></x-card>
        </div>
    @endif
    <x-card class="mt-4">
        <x-table :headers="[__('application.location_d219c681'), __('terminology.external_revenue_93ddb07f'), __('terminology.location_cogs_a1fa9c9f'), __('terminology.outlet_margin_486266b4'), __('terminology.internal_sales_out_6adef4b9'), __('terminology.source_internal_cost_d30036fd'), __('terminology.internal_purchases_in_bbd49378'), __('terminology.source_internal_margin_af834945'), __('terminology.company_stock_value_ed3284c1'), __('terminology.location_commercial_value_44825cc6')]">
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
        <p class="mt-3 text-xs text-slate-500">{{ __('application.internal_source_and_outlet_margins_are_analytical_views_they_are_excluded_f_74217637') }}</p>
    </x-card>
</div>
