@if (session('success') || session('error'))
    <div class="fixed right-4 top-20 z-50 w-[calc(100%-2rem)] max-w-md space-y-3 sm:right-6" x-data="{ show: true }" x-init="setTimeout(() => show = false, 5000)" x-show="show" x-transition>
        @if (session('success'))
            <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800 shadow-soft dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800 shadow-soft dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
                {{ session('error') }}
            </div>
        @endif
    </div>
@endif
