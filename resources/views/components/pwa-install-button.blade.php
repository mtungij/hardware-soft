@php
    $branding = app(\App\Services\PwaBrandingService::class);
    $pwaBrand = $branding->brand();
    $brandName = $pwaBrand['name'];
    $brandIcon = $branding->iconUrl(192, $pwaBrand);
@endphp

@props([
    'class' => 'hidden inline-flex h-10 w-10 items-center justify-center rounded-xl bg-build-orange text-white shadow-lg shadow-orange-500/25 transition hover:brightness-95 disabled:cursor-wait disabled:opacity-70',
])

<div data-pwa-install-root data-pwa-brand-name="{{ $brandName }}">
    <button
        type="button"
        data-pwa-install-button
        aria-label="Install {{ $brandName }} App"
        title="Install {{ $brandName }} App"
        class="{{ $class }}"
    >
        <svg aria-hidden="true" class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 4v10"></path>
            <path d="m7 9 5 5 5-5"></path>
            <path d="M5 20h14"></path>
        </svg>
        <span class="sr-only" data-pwa-install-label>{{ __('application.install_fd6c3ebf') }} {{ $brandName }} {{ __('application.app_fc4a695f') }}</span>
        <span class="sr-only hidden" data-pwa-install-loading>{{ __('messages.receipts.uploading') }}</span>
    </button>

    <div data-pwa-ios-modal aria-hidden="true" class="hidden fixed inset-0 z-[9998] bg-slate-950/60 p-4 backdrop-blur-sm">
        <div class="mx-auto mt-20 max-w-sm rounded-2xl border border-slate-200 bg-white p-5 text-slate-900 shadow-2xl dark:border-slate-700 dark:bg-slate-900 dark:text-white">
            <div class="flex items-center gap-3">
                <img src="{{ $brandIcon }}" alt="{{ $brandName }}" class="h-10 w-10 rounded-xl bg-white object-contain p-1">
                <div>
                    <h2 class="text-base font-black">{{ __('application.install_fd6c3ebf') }} {{ $brandName }} {{ __('application.app_fc4a695f') }}</h2>
                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ __('application.this_app_will_use_eb959a48') }} {{ $brandName }} {{ __('application.branding_iphone_ipad_safari_e4714a8c') }}</p>
                </div>
            </div>
            <ol class="mt-4 list-decimal space-y-2 pl-5 text-sm font-semibold leading-6 text-slate-600 dark:text-slate-300">
                <li>{{ __('application.tap_the_share_icon_in_safari_0e91467f') }}</li>
                <li>{{ __('application.choose_add_to_home_screen_11370075') }}</li>
                <li>{{ __('application.click_add_fdb4686a') }}</li>
            </ol>
            <button type="button" data-pwa-ios-close class="mt-5 w-full rounded-xl bg-build-orange px-4 py-3 text-sm font-black text-white">
                {{ __('application.ok_9ce3bd42') }}
            </button>
        </div>
    </div>

    <div data-pwa-help-modal aria-hidden="true" class="hidden fixed inset-0 z-[9998] bg-slate-950/60 p-4 backdrop-blur-sm">
        <div class="mx-auto mt-20 max-w-sm rounded-2xl border border-slate-200 bg-white p-5 text-slate-900 shadow-2xl dark:border-slate-700 dark:bg-slate-900 dark:text-white">
            <div class="flex items-center gap-3">
                <img src="{{ $brandIcon }}" alt="{{ $brandName }}" class="h-10 w-10 rounded-xl bg-white object-contain p-1">
                <div>
                    <h2 class="text-base font-black">{{ __('application.install_fd6c3ebf') }} {{ $brandName }} {{ __('application.app_fc4a695f') }}</h2>
                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ __('application.this_app_will_use_eb959a48') }} {{ $brandName }} {{ __('application.branding_710860c0') }}</p>
                </div>
            </div>
            <div class="mt-4 space-y-3 text-sm font-semibold leading-6 text-slate-600 dark:text-slate-300">
                <p><strong>Chrome / Edge:</strong> {{ __('application.open_the_browser_menu_then_choose_install_app_or_add_to_home_screen_ed7352a7') }}</p>
                <p><strong>Android:</strong> {{ __('application.tap_the_three_dots_menu_then_choose_install_app_fc62c03c') }}</p>
                <p><strong>iPhone:</strong> {{ __('application.open_safari_share_then_choose_add_to_home_screen_2301e648') }}</p>
            </div>
            <button type="button" data-pwa-help-close class="mt-5 w-full rounded-xl bg-build-orange px-4 py-3 text-sm font-black text-white">
                {{ __('application.ok_9ce3bd42') }}
            </button>
        </div>
    </div>
</div>
