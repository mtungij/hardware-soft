<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
         <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Hardex POS') }}</title>

        @php
            $themeColor = '#f97316';

            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('settings')) {
                    $savedThemeColor = \App\Models\Setting::query()->value('theme_color');

                    if (is_string($savedThemeColor) && preg_match('/^#[0-9A-Fa-f]{6}$/', $savedThemeColor)) {
                        $themeColor = $savedThemeColor;
                    }
                }
            } catch (\Throwable) {
                $themeColor = '#f97316';
            }
        @endphp

        <style>
            :root {
                --build-theme: {{ $themeColor }};
            }
        </style>
        <x-theme-script />

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=poppins:300,400,500,600,700&display=swap" rel="stylesheet" />
        <x-pwa-head />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans antialiased">
        <div
            x-data="{
                darkMode: window.hardexTheme?.get() === 'dark',
                toggleTheme() {
                    this.darkMode = window.hardexTheme?.toggle() === 'dark';
                }
            }"
            x-init="window.addEventListener('hardex-theme-changed', event => darkMode = event.detail.theme === 'dark')"
            class="min-h-screen bg-slate-100 text-slate-900 transition-colors duration-300 dark:bg-slate-950 dark:text-slate-100"
        >
            <div class="grid min-h-screen lg:grid-cols-[1fr_520px]">
                <section class="hidden bg-navy-900 p-10 text-white lg:flex lg:flex-col lg:justify-between dark:bg-slate-900">
                    <div class="flex items-center gap-3">
                        <div class="grid h-12 w-12 place-items-center rounded-xl bg-build-orange text-lg font-black">HP</div>
                        <div>
                            <p class="font-black uppercase tracking-wide">Hardex</p>
                            <p class="text-sm text-slate-300">POS</p>
                        </div>
                    </div>
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-wide text-orange-300">Enterprise POS</p>
                        <h1 class="mt-3 max-w-xl text-4xl font-black leading-tight">Run sales, stock, accounting, and branches from one workspace.</h1>
                        <p class="mt-4 max-w-lg text-slate-300">Hardex POS gives construction material businesses a clean, secure operating system for daily work.</p>
                    </div>
                    <p class="text-sm text-slate-400">Laravel + Livewire + Volt + Tailwind CSS</p>
                </section>

                <section class="flex min-h-screen items-center justify-center px-4 py-10">
                    <div class="w-full max-w-md">
                        <div class="mb-4 flex justify-end">
                            <x-pwa-install-button class="mr-2 hidden h-10 w-10 items-center justify-center rounded-lg bg-build-orange text-white shadow-lg shadow-orange-500/25" />
                            <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700" @click="toggleTheme()" x-text="darkMode ? 'Light' : 'Dark'"></button>
                        </div>
                        <div class="mb-6 text-center lg:hidden">
                            <div class="mx-auto grid h-14 w-14 place-items-center rounded-xl bg-build-orange text-lg font-black text-white">HP</div>
                            <h1 class="mt-3 text-xl font-black text-navy-900 dark:text-white">Hardex POS</h1>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-soft dark:border-slate-800 dark:bg-slate-900">
                            {{ $slot }}
                        </div>
                    </div>
                </section>
            </div>
        </div>

        @livewireScripts
    </body>
</html>
