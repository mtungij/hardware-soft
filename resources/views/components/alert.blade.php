@props([
    'tone' => 'info',
    'title' => null,
])

@php
    $tones = [
        'success' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
        'warning' => 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300',
        'danger' => 'border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-300',
        'info' => 'border-cyan-500/30 bg-cyan-500/10 text-cyan-700 dark:text-cyan-300',
    ];
@endphp

<div {{ $attributes->merge(['class' => 'rounded-xl border p-4 text-sm '.$tones[$tone] ?? $tones['info']]) }} role="alert">
    @if ($title)
        <p class="font-semibold">{{ $title }}</p>
    @endif
    <div class="{{ $title ? 'mt-1' : '' }}">{{ $slot }}</div>
</div>
