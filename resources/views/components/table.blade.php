@props([
    'headers' => [],
])

<div {{ $attributes->merge(['class' => 'max-w-full overflow-x-auto rounded-xl border border-slate-200 shadow-sm dark:border-slate-800']) }}>
    <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
        @if ($headers)
            <thead class="sticky top-0 z-10 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-300">
                <tr>
                    @foreach ($headers as $header)
                        <th class="px-4 py-3 font-black">{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
        @endif
        <tbody class="divide-y divide-slate-100 bg-white dark:divide-slate-800 dark:bg-slate-900">
            {{ $slot }}
        </tbody>
    </table>
</div>
