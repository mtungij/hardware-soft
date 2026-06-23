@props([
    'class' => 'hidden inline-flex items-center justify-center gap-2 rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white shadow-lg shadow-orange-500/25 transition hover:brightness-95 disabled:cursor-wait disabled:opacity-70',
])

<div data-pwa-install-root>
    <button
        type="button"
        data-pwa-install-button
        class="{{ $class }}"
    >
        <img src="{{ asset('images/hardex.png') }}" alt="" class="h-5 w-5 rounded bg-white object-contain p-0.5">
        <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 3v12"></path>
            <path d="m7 10 5 5 5-5"></path>
            <path d="M5 21h14"></path>
        </svg>
        <span data-pwa-install-label>{{ __('messages.install_app') }}</span>
        <span class="hidden" data-pwa-install-loading>{{ __('messages.receipts.uploading') }}</span>
    </button>

    <div data-pwa-ios-modal aria-hidden="true" class="hidden fixed inset-0 z-[9998] bg-slate-950/60 p-4 backdrop-blur-sm">
        <div class="mx-auto mt-20 max-w-sm rounded-2xl border border-slate-200 bg-white p-5 text-slate-900 shadow-2xl dark:border-slate-700 dark:bg-slate-900 dark:text-white">
            <div class="flex items-center gap-3">
                <img src="{{ asset('images/hardex.png') }}" alt="Hardex" class="h-10 w-10 rounded-xl bg-white object-contain p-1">
                <div>
                    <h2 class="text-base font-black">Jinsi ya ku-install Hardex App</h2>
                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">iPhone / iPad Safari</p>
                </div>
            </div>
            <ol class="mt-4 list-decimal space-y-2 pl-5 text-sm font-semibold leading-6 text-slate-600 dark:text-slate-300">
                <li>Bonyeza Share icon kwenye Safari.</li>
                <li>Chagua Add to Home Screen.</li>
                <li>Bonyeza Add.</li>
            </ol>
            <button type="button" data-pwa-ios-close class="mt-5 w-full rounded-xl bg-build-orange px-4 py-3 text-sm font-black text-white">
                Sawa
            </button>
        </div>
    </div>

    <div data-pwa-help-modal aria-hidden="true" class="hidden fixed inset-0 z-[9998] bg-slate-950/60 p-4 backdrop-blur-sm">
        <div class="mx-auto mt-20 max-w-sm rounded-2xl border border-slate-200 bg-white p-5 text-slate-900 shadow-2xl dark:border-slate-700 dark:bg-slate-900 dark:text-white">
            <div class="flex items-center gap-3">
                <img src="{{ asset('images/hardex.png') }}" alt="Hardex" class="h-10 w-10 rounded-xl bg-white object-contain p-1">
                <div>
                    <h2 class="text-base font-black">Install Hardex App</h2>
                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">Browser install instructions</p>
                </div>
            </div>
            <div class="mt-4 space-y-3 text-sm font-semibold leading-6 text-slate-600 dark:text-slate-300">
                <p><strong>Chrome / Edge:</strong> open the browser menu, then choose Install app or Add to Home screen.</p>
                <p><strong>Android:</strong> tap the three dots menu, then choose Install app.</p>
                <p><strong>iPhone:</strong> open Safari Share, then choose Add to Home Screen.</p>
            </div>
            <button type="button" data-pwa-help-close class="mt-5 w-full rounded-xl bg-build-orange px-4 py-3 text-sm font-black text-white">
                OK
            </button>
        </div>
    </div>
</div>
