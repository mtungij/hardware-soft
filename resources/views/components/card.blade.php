@props([
    'title' => null,
    'description' => null,
])

<article {{ $attributes->merge(['class' => 'erp-surface erp-surface-hover p-4 sm:p-5']) }}>
    @if ($title || $description)
        <div class="mb-4">
            @if ($title)
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $title }}</h2>
            @endif
            @if ($description)
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $description }}</p>
            @endif
        </div>
    @endif

    {{ $slot }}
</article>
