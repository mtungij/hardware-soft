<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center gap-x-2 rounded-lg bg-red-500 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-red-600 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 focus:ring-offset-white disabled:pointer-events-none disabled:opacity-50 dark:focus:ring-offset-slate-950']) }}>
    {{ $slot }}
</button>
