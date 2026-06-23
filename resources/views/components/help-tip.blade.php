@props([
    'title' => 'Kidokezo',
])

<div {{ $attributes->merge(['class' => 'rounded-xl border border-cyan-200 bg-cyan-50 p-3 text-sm text-cyan-900 dark:border-cyan-500/30 dark:bg-cyan-500/10 dark:text-cyan-100']) }}>
    <div class="flex items-start gap-2">
        <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-cyan-600 text-xs font-black text-white">?</span>
        <div>
            <p class="font-bold">{{ $title }}</p>
            <p class="mt-1 leading-6">{{ $slot }}</p>
        </div>
    </div>
</div>
