@props([
    'label',
    'value',
    'trend' => null,
    'tone' => 'info',
])

@php
    $tones = [
        'success' => 'text-emerald-500 bg-emerald-500/10',
        'warning' => 'text-amber-500 bg-amber-500/10',
        'danger' => 'text-red-500 bg-red-500/10',
        'info' => 'text-cyan-500 bg-cyan-500/10',
        'primary' => 'text-build-orange bg-orange-500/10',
    ];
@endphp

<article {{ $attributes->merge(['class' => 'erp-surface erp-surface-hover p-5']) }}>
    <div class="flex items-start justify-between gap-4">
        <div>
            <p class="text-sm font-medium text-slate-400">{{ $label }}</p>
            <p class="mt-2 text-2xl font-bold text-slate-900 dark:text-white">{{ $value }}</p>
        </div>
        <div class="grid h-10 w-10 place-items-center rounded-lg {{ $tones[$tone] ?? $tones['info'] }}">
            {{ $icon ?? '' }}
        </div>
    </div>
    @if ($trend)
        <p class="mt-4 text-xs font-medium text-slate-500 dark:text-slate-400">{{ $trend }}</p>
    @endif
</article>
