<div {{ $attributes->merge(['class' => 'animate-pulse rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900']) }}>
    <div class="mb-4 h-9 w-full rounded bg-slate-200 dark:bg-slate-700"></div>
    @foreach (range(1, 5) as $row)
        <div class="mb-3 grid grid-cols-4 gap-3">
            <div class="h-4 rounded bg-slate-200 dark:bg-slate-700"></div>
            <div class="h-4 rounded bg-slate-200 dark:bg-slate-700"></div>
            <div class="h-4 rounded bg-slate-200 dark:bg-slate-700"></div>
            <div class="h-4 rounded bg-slate-200 dark:bg-slate-700"></div>
        </div>
    @endforeach
</div>
