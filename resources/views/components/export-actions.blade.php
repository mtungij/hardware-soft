@props([
    'export',
    'params' => [],
    'print' => true,
])

@php
    $query = collect($params)->filter(fn ($value) => filled($value))->all();
    $pdfUrl = route('exports.download', ['export' => $export, 'format' => 'pdf'] + $query);
    $excelUrl = route('exports.download', ['export' => $export, 'format' => 'excel'] + $query);
    $canPdf = auth()->user()?->can('export pdf') ?? false;
    $canExcel = auth()->user()?->can('export excel') ?? false;
    $canPrint = auth()->user()?->can('print reports') ?? false;
@endphp

<div class="hidden gap-2 sm:flex">
    @if ($canPdf)
        <a href="{{ $pdfUrl }}" class="inline-flex items-center gap-2 rounded-lg border border-red-200 bg-white px-3 py-2 text-sm font-bold text-red-700 shadow-sm hover:bg-red-50 dark:border-red-500/30 dark:bg-slate-900 dark:text-red-300">
            <span aria-hidden="true">PDF</span>
            Download PDF
        </a>
    @endif
    @if ($canExcel)
        <a href="{{ $excelUrl }}" class="inline-flex items-center gap-2 rounded-lg border border-emerald-200 bg-white px-3 py-2 text-sm font-bold text-emerald-700 shadow-sm hover:bg-emerald-50 dark:border-emerald-500/30 dark:bg-slate-900 dark:text-emerald-300">
            <span aria-hidden="true">XLS</span>
            Export Excel
        </a>
    @endif
    @if ($print && $canPrint)
        <button type="button" onclick="window.print()" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-700 shadow-sm hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
            <span aria-hidden="true">PRN</span>
            Print
        </button>
    @endif
</div>

@if ($canPdf || $canExcel || ($print && $canPrint))
    <details class="relative sm:hidden">
        <summary class="list-none rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-bold dark:border-slate-700 dark:bg-slate-900">Export Options</summary>
        <div class="absolute right-0 z-40 mt-2 grid w-44 gap-1 rounded-xl border border-slate-200 bg-white p-2 shadow-xl dark:border-slate-700 dark:bg-slate-900">
            @if ($canPdf)
                <a href="{{ $pdfUrl }}" class="rounded-lg px-3 py-2 text-sm font-bold hover:bg-red-50 dark:hover:bg-red-500/10">Download PDF</a>
            @endif
            @if ($canExcel)
                <a href="{{ $excelUrl }}" class="rounded-lg px-3 py-2 text-sm font-bold hover:bg-emerald-50 dark:hover:bg-emerald-500/10">Export Excel</a>
            @endif
            @if ($print && $canPrint)
                <button type="button" onclick="window.print()" class="rounded-lg px-3 py-2 text-left text-sm font-bold hover:bg-slate-50 dark:hover:bg-white/5">Print</button>
            @endif
        </div>
    </details>
@endif
