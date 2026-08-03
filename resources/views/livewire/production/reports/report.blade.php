<?php

use App\Services\ProductionReportService;
use App\Support\CompanyFeatures;
use Illuminate\Validation\Rule;
use Livewire\WithPagination;
use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

layout('layouts.app'); uses([WithPagination::class]);
state(['report' => '']);
state(['date_from' => '', 'date_to' => '', 'branch_id' => '', 'family_id' => '', 'product_id' => '', 'machine_id' => '', 'mould_id' => '', 'status' => '', 'group_by' => '', 'search' => ''])->url(except: '');
mount(function (): void {
    $this->report = (string) request()->route('report');
    app(ProductionReportService::class)->definition($this->report);
    abort_unless(auth()->user()?->can('production.view_reports'), 403);
    abort_if($this->report === 'costing' && ! auth()->user()?->can('production.view_cost_reports'), 403);
    $this->date_from = request()->string('date_from')->toString() ?: now(CompanyFeatures::currentCompany()?->timezone ?: config('app.timezone'))->startOfMonth()->toDateString();
    $this->date_to = request()->string('date_to')->toString() ?: CompanyFeatures::localDate();
});
$filters = fn () => collect(['date_from','date_to','branch_id','family_id','product_id','machine_id','mould_id','status','group_by','search'])->mapWithKeys(fn ($key) => [$key => $this->{$key}])->all();
$applyFilters = function (): void { $this->validate(['date_from'=>['nullable','date'],'date_to'=>['nullable','date','after_or_equal:date_from']]); $this->resetPage(); };
$resetFilters = function (): void { $this->reset('branch_id','family_id','product_id','machine_id','mould_id','status','group_by','search'); $this->date_from = now(CompanyFeatures::currentCompany()?->timezone ?: config('app.timezone'))->startOfMonth()->toDateString(); $this->date_to = CompanyFeatures::localDate(); $this->resetPage(); };
$preset = function (string $preset): void { $now = now(CompanyFeatures::currentCompany()?->timezone ?: config('app.timezone')); [$from,$to] = match($preset) { 'today'=>[$now->copy(),$now->copy()], 'week'=>[$now->copy()->startOfWeek(),$now->copy()->endOfWeek()], 'month'=>[$now->copy()->startOfMonth(),$now->copy()->endOfMonth()], 'previous'=>[$now->copy()->subMonthNoOverflow()->startOfMonth(),$now->copy()->subMonthNoOverflow()->endOfMonth()], 'year'=>[$now->copy()->startOfYear(),$now->copy()->endOfYear()], default=>[$now->copy()->startOfMonth(),$now] }; $this->date_from=$from->toDateString(); $this->date_to=$to->toDateString(); $this->resetPage(); };
?>
<div class="space-y-5">
    @php($service = app(ProductionReportService::class))
    @php($definition = $service->definition($report))
    <x-page-header :title="$definition['title']" :description="$definition['description']" :breadcrumbs="['Dashboard'=>route('dashboard'),'Production'=>route('production.index'),'Reports'=>route('production.reports.index'),$definition['title']=>null]" />
    <x-production-report-filters :options="$service->filterOptions()" :report="$report" />
    @php($filterValues = $this->filters())
    @php($data = $service->report($report, $filterValues))
    <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-navy-900"><p class="text-xs font-bold uppercase text-slate-500">Matching Records</p><p class="mt-1 text-2xl font-black">{{ number_format($data['paginator']?->total() ?? count($data['rows'])) }}</p></div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-navy-900"><p class="text-xs font-bold uppercase text-slate-500">Reporting Period</p><p class="mt-1 font-black">{{ $date_from ?: 'Beginning' }} → {{ $date_to ?: 'Today' }}</p></div>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-950/30"><p class="text-xs font-bold uppercase text-emerald-700 dark:text-emerald-300">Data Integrity</p><p class="mt-1 font-black text-emerald-800 dark:text-emerald-200">Read-only historical source</p></div>
    </div>
    <div class="flex flex-wrap justify-end gap-2 print:hidden">
        <button onclick="window.print()" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold dark:border-slate-600">Print</button>
        @can('production.export_reports')
            <a href="{{ route('production.reports.export', ['report'=>$report,'format'=>'pdf',...$filterValues]) }}" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-bold text-white">PDF</a>
            <a href="{{ route('production.reports.export', ['report'=>$report,'format'=>'excel',...$filterValues]) }}" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-bold text-white">Excel / CSV</a>
        @endcan
    </div>
    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-navy-900">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm"><thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-600 dark:bg-navy-950 dark:text-slate-300"><tr>@foreach($data['headers'] as $header)<th scope="col" class="whitespace-nowrap px-4 py-3">{{ $header }}</th>@endforeach</tr></thead><tbody class="divide-y divide-slate-100 dark:divide-slate-800">@forelse($data['rows'] as $row)<tr class="align-top hover:bg-slate-50 dark:hover:bg-navy-800">@foreach($row as $value)<td class="whitespace-nowrap px-4 py-3 text-slate-700 dark:text-slate-200">{{ $value }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($data['headers']) }}" class="px-5 py-14 text-center text-slate-500">No records match the selected filters.</td></tr>@endforelse</tbody></table>
        </div>
        @if($data['paginator'])<div class="border-t border-slate-200 p-4 dark:border-slate-700">{{ $data['paginator']->links() }}</div>@endif
    </div>
    @if(($data['totals'] ?? []) !== [])<div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-navy-900"><h2 class="mb-3 font-black">Report Totals</h2><dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">@foreach($data['totals'] as $label=>$value)<div class="rounded-lg bg-slate-50 p-3 dark:bg-navy-950"><dt class="text-xs font-bold uppercase text-slate-500">{{ $label }}</dt><dd class="mt-1 font-black">{{ $value }}</dd></div>@endforeach</dl></div>@endif
</div>
