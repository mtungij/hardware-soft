@props([
    'title',
    'description' => null,
    'breadcrumbs' => [],
])

<section class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div>
        @if ($breadcrumbs)
            <nav class="mb-2 flex flex-wrap items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                @foreach ($breadcrumbs as $label => $url)
                    @if ($url)
                        <a class="hover:text-build-orange" href="{{ $url }}" wire:navigate>{{ $label }}</a>
                    @else
                        <span class="font-semibold text-slate-900 dark:text-slate-200">{{ $label }}</span>
                    @endif
                    @unless ($loop->last)
                        <span class="text-slate-300 dark:text-slate-600">/</span>
                    @endunless
                @endforeach
            </nav>
        @endif

        <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">{{ $title }}</h1>

        @if ($description)
            <p class="mt-2 max-w-3xl text-sm text-slate-500 dark:text-slate-400">{{ $description }}</p>
        @endif
    </div>

    @if ($slot->isNotEmpty())
        <div class="flex flex-wrap gap-2 lg:justify-end">{{ $slot }}</div>
    @endif
</section>
