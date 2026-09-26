@props(['summary', 'unit' => ''])

@php
    $change = $summary['change'];
    $tone = $change > 0
        ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200'
        : ($change < 0
            ? 'bg-orange-50 text-orange-700 dark:bg-orange-500/15 dark:text-orange-200'
            : 'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-200');
@endphp

<div class="min-w-0 space-y-1 text-xs">
    <span class="inline-flex rounded-full px-2 py-0.5 font-black {{ $tone }}">{{ $summary['action'] }}</span>
    @if ($change !== null)
        <p class="font-semibold text-slate-700 dark:text-slate-200">
            {{ \App\Support\NumberFormatter::quantity($summary['before']) }} → {{ \App\Support\NumberFormatter::quantity($summary['after']) }} {{ $unit }}
            <span class="ml-1 {{ $change < 0 ? 'text-orange-700 dark:text-orange-300' : 'text-emerald-700 dark:text-emerald-300' }}">({{ $change > 0 ? '+' : '' }}{{ \App\Support\NumberFormatter::quantity($change) }})</span>
        </p>
        <p class="text-slate-500 dark:text-slate-400">{{ $summary['reference'] }} · {{ $summary['date']?->format('d M Y') }}</p>
    @else
        <p class="text-slate-500 dark:text-slate-400">Before — · Change — · Reference — · Date —</p>
    @endif
</div>
