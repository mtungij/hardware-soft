@props([
    'title',
    'description' => null,
    'breadcrumbs' => [],
])

<section class="mb-6 flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-5 lg:flex-row lg:items-end lg:justify-between">
    <div>
        @if ($breadcrumbs)
            <nav class="mb-2 flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                @foreach ($breadcrumbs as $label => $url)
                    @if ($url)
                        <a class="hover:text-build-orange" href="{{ $url }}" wire:navigate>{{ $label }}</a>
                    @else
                        <span class="text-build-orange">{{ $label }}</span>
                    @endif
                    @unless ($loop->last)
                        <span>/</span>
                    @endunless
                @endforeach
            </nav>
        @endif

        <h1 class="text-2xl font-black tracking-tight text-navy-900 dark:text-white sm:text-3xl">{{ $title }}</h1>

        @if ($description)
            <p class="mt-2 max-w-3xl text-sm text-slate-500 dark:text-slate-400">{{ $description }}</p>
        @endif
    </div>

    @if ($slot->isNotEmpty())
        <div class="flex flex-wrap gap-2 lg:justify-end">{{ $slot }}</div>
    @endif
</section>
