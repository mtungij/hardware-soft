@props([
    'title' => null,
    'description' => null,
])

<article {{ $attributes->merge(['class' => 'rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition-colors duration-300 dark:border-slate-800 dark:bg-slate-900 sm:p-5']) }}>
    @if ($title || $description)
        <div class="mb-4">
            @if ($title)
                <h2 class="text-base font-black text-navy-900 dark:text-white sm:text-lg">{{ $title }}</h2>
            @endif
            @if ($description)
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $description }}</p>
            @endif
        </div>
    @endif

    {{ $slot }}
</article>
