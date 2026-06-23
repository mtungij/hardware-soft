@props([
    'title' => null,
])

<article {{ $attributes->merge(['class' => 'rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-navy-900']) }}>
    @if ($title)
        <h2 class="mb-4 text-lg font-black text-navy-900 dark:text-white">{{ $title }}</h2>
    @endif

    {{ $slot }}
</article>
