<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#f97316">
        <title>{{ __('application.hardex_customer_offline_e0f45df7') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-100 font-sans text-slate-900 dark:bg-slate-950 dark:text-white">
        <main class="flex min-h-screen items-center justify-center p-6">
            <section class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-soft dark:border-slate-800 dark:bg-slate-900">
                <img src="{{ asset('images/hardex.png') }}" alt="Hardex" class="mx-auto h-16 w-16 rounded-2xl bg-white object-contain p-2 shadow-sm">
                <h1 class="mt-6 text-xl font-black">{{ __('application.you_are_currently_offline_e1c483db') }}</h1>
                <p class="mt-3 text-sm font-semibold leading-6 text-slate-500 dark:text-slate-300">{{ __('application.please_check_your_internet_connection_and_try_again_10cd84f5') }}</p>
                <button type="button" onclick="window.location.reload()" class="mt-6 inline-flex w-full items-center justify-center rounded-xl bg-build-orange px-4 py-3 text-sm font-black text-white">
                    {{ __('application.try_again_cef2fe09') }}
                </button>
            </section>
        </main>
        <x-localization />
</body>
</html>
