<?php

use App\Services\ProductionReportService;
use App\Support\CompanyFeatures;
use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['date_from' => fn () => now(CompanyFeatures::currentCompany()?->timezone ?: config('app.timezone'))->startOfMonth()->toDateString(), 'date_to' => fn () => CompanyFeatures::localDate()])->url(except: '');
mount(fn () => abort_unless(auth()->user()?->can('production.view_reports'), 403));
?>
<div class="space-y-6">
    <x-page-header title="{{ __('application.production_reports_8889d604') }}" description="{{ __('application.read_only_operational_quality_costing_and_traceability_reporting_from_histo_74f6288b') }}" :breadcrumbs="['Dashboard'=>route('dashboard'),'Production'=>route('production.index'),'Reports'=>null]" />
    @php($service = app(ProductionReportService::class))
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach($service->dashboard(compact('date_from','date_to')) as $kpi)
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-navy-900"><p class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ $kpi['label'] }}</p><p class="mt-2 text-xl font-black text-slate-900 dark:text-white">{{ $kpi['value'] }}</p></div>
        @endforeach
    </div>
    @php($chart = $service->dailyAcceptedChart(compact('date_from','date_to')))
    @if($chart['labels'] !== [])
        @php($chartDatasets = collect($chart['datasets'])->values()->map(function ($set, $index) { return [...$set, 'borderColor' => ['#0891b2','#f97316','#16a34a','#7c3aed'][$index % 4], 'backgroundColor' => 'transparent', 'tension' => .25]; })->all())
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-navy-900"><h2 class="font-black">{{ __('application.daily_accepted_production_090a9b1f') }}</h2><p class="mb-4 text-sm text-slate-500">{{ __('application.each_unit_is_kept_in_a_separate_series_the_reports_below_remain_the_authori_df8f88a5') }}</p><div class="h-72" x-data="{ render(){ buildMartChart($refs.canvas,{type:'line',data:{labels:@js($chart['labels']),datasets:@js($chartDatasets)}})} }" x-init="render(); window.addEventListener('buildmart-theme-changed',()=>render())"><canvas x-ref="canvas" aria-label="{{ __('application.daily_accepted_production_chart_by_unit_386a7685') }}"></canvas></div><p class="sr-only">{{ __('application.accepted_production_is_displayed_by_date_with_separate_datasets_for_each_pr_7ae98561') }}</p></div>
    @endif
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach(ProductionReportService::REPORTS as $slug => $report)
            @continue(($report['cost'] ?? false) && ! auth()->user()?->can('production.view_cost_reports'))
            <a href="{{ route('production.reports.'.str_replace('-', '_', $slug), ['date_from'=>$date_from,'date_to'=>$date_to]) }}" wire:navigate class="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:border-cyan-400 hover:shadow-lg dark:border-slate-700 dark:bg-navy-900">
                <div class="flex items-start justify-between"><div><h2 class="text-lg font-black text-slate-900 dark:text-white">{{ \App\Support\UiText::translate($report['title']) }}</h2><p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ __($report['description']) }}</p></div><span class="text-2xl text-cyan-600" aria-hidden="true">→</span></div>
            </a>
        @endforeach
        <a href="{{ route('production.reports.batch_traceability') }}" wire:navigate class="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:border-cyan-400 hover:shadow-lg dark:border-slate-700 dark:bg-navy-900"><h2 class="text-lg font-black">{{ __('application.batch_traceability_c924edb4') }}</h2><p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ __('application.follow_one_batch_from_immutable_recipe_and_materials_through_qc_release_and_55255dc8') }}</p></a>
    </div>
</div>
