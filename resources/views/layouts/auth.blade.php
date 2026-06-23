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
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
        <x-pwa-head />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans antialiased">
        <div class="fixed right-4 top-4 z-50">
            <x-customer-language-switcher />
        </div>

        {{ $slot }}

        @livewireScripts
    </body>
</html>
