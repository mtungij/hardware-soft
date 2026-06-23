<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
         <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ \App\Models\Company::current()?->company_name ?: __('messages.customer_portal') }}</title>

        @php
            $themeColor = '#f97316';
            $settings = null;
            $company = \App\Models\Company::current();

            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('settings')) {
                    $settings = \App\Models\Setting::query()->first();
                    $themeColor = $settings?->theme_color ?: $themeColor;
                }
            } catch (\Throwable) {
                $settings = null;
            }

            $companyName = $company?->company_name ?: ($settings?->company_name ?: 'Customer Portal');
            $companyLogo = $company?->logo ?: $settings?->company_logo;
            $whatsappNumber = $company?->whatsapp_number ?: $settings?->whatsapp_number;
            $whatsappLink = $company?->whatsappLink() ?: ($whatsappNumber ? 'https://wa.me/'.preg_replace('/\D+/', '', $whatsappNumber) : null);
            $initials = collect(preg_split('/\s+/', trim($companyName)))->filter()->map(fn ($word) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($word, 0, 1)))->take(2)->join('') ?: 'HX';
        @endphp

        <style>:root { --build-theme: {{ preg_match('/^#[0-9A-Fa-f]{6}$/', $themeColor) ? $themeColor : '#f97316' }}; }</style>
        <x-theme-script />
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=poppins:300,400,500,600,700&display=swap" rel="stylesheet" />
        <x-pwa-head />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans antialiased">
        @php
            $customerAccount = auth('customer')->user();
            $unreadNotifications = $customerAccount
                ? \App\Models\CustomerNotification::where('customer_id', $customerAccount->customer_id)->whereNull('read_at')->count()
                : 0;
            $recentNotifications = $customerAccount
                ? \App\Models\CustomerNotification::where('customer_id', $customerAccount->customer_id)->latest()->limit(5)->get()
                : collect();
            $navItems = [
                ['label' => __('messages.nav.dashboard'), 'route' => 'customer.dashboard'],
                ['label' => __('messages.nav.debts'), 'route' => 'customer.debts.index'],
                ['label' => __('messages.nav.upload_receipt'), 'route' => 'customer.receipts.create'],
                ['label' => __('messages.nav.deposits'), 'route' => 'customer.deposits.index'],
                ['label' => __('messages.nav.statements'), 'route' => 'customer.statement'],
                ['label' => __('messages.nav.notifications'), 'route' => 'customer.notifications.index'],
                ['label' => __('messages.nav.profile'), 'route' => 'customer.profile'],
            ];
        @endphp

        <div x-data="{ sidebarOpen: false, profileOpen: false, notificationsOpen: false, darkMode: window.hardexTheme?.get() === 'dark' }" x-init="window.addEventListener('hardex-theme-changed', event => darkMode = event.detail.theme === 'dark')" class="min-h-screen bg-slate-100 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
            <div x-cloak x-show="sidebarOpen" class="fixed inset-0 z-40 bg-slate-950/50 lg:hidden" @click="sidebarOpen = false"></div>

            <aside class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-slate-200 bg-white transition dark:border-slate-800 dark:bg-slate-900 lg:translate-x-0" :class="{ '-translate-x-full': !sidebarOpen, 'translate-x-0': sidebarOpen }">
                <div class="flex h-16 items-center gap-3 border-b border-slate-200 px-4 dark:border-slate-800">
                    <div class="grid h-11 w-11 place-items-center overflow-hidden rounded-xl bg-build-orange text-lg font-black text-white shadow-lg shadow-orange-500/25">
                        @if ($companyLogo)
                            <img src="{{ asset('storage/'.$companyLogo) }}" class="h-full w-full bg-white object-contain p-1.5" alt="{{ $companyName }} logo">
                        @else
                            {{ $initials }}
                        @endif
                    </div>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-black uppercase text-navy-900 dark:text-white">{{ $companyName }}</p>
                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ __('messages.customer_portal') }}</p>
                    </div>
                </div>

                <nav class="flex-1 space-y-1 overflow-y-auto p-3">
                    @foreach ($navItems as $item)
                        @php $isActive = request()->routeIs($item['route']) || request()->routeIs(str($item['route'])->beforeLast('.').'.*'); @endphp
                        <a href="{{ route($item['route']) }}" wire:navigate @click="sidebarOpen = false" class="flex items-center gap-3 rounded-xl px-3 py-3 text-sm font-bold transition {{ $isActive ? 'bg-orange-50 text-build-orange dark:bg-orange-500/15 dark:text-orange-200' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/5' }}">
                            <span class="grid h-9 w-9 place-items-center rounded-lg {{ $isActive ? 'bg-build-orange text-white' : 'bg-slate-100 text-slate-500 dark:bg-white/5 dark:text-slate-300' }}">{{ collect(explode(' ', $item['label']))->map(fn ($word) => $word[0])->take(2)->join('') }}</span>
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </nav>

                <div class="border-t border-slate-200 p-4 dark:border-slate-800">
                    <div class="rounded-xl bg-emerald-50 p-4 text-sm font-bold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200">
                        <p>{{ __('messages.support.need_help') }}</p>
                        <p class="mt-1">{{ __('messages.support.whatsapp_support') }}</p>
                        @if ($whatsappNumber && $whatsappLink)
                            <a href="{{ $whatsappLink }}" target="_blank" rel="noopener" class="mt-2 inline-flex rounded-lg bg-emerald-600 px-3 py-2 text-xs font-black text-white">{{ __('messages.support.chat_whatsapp') }}</a>
                            <p class="mt-2 text-xs font-semibold">{{ $whatsappNumber }}</p>
                        @endif
                    </div>
                </div>
            </aside>

            <div class="lg:pl-72">
                <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/90 backdrop-blur dark:border-slate-800 dark:bg-slate-950/90">
                    <div class="flex h-16 items-center gap-3 px-4 sm:px-6">
                        <button class="grid h-10 w-10 place-items-center rounded-lg border border-slate-200 dark:border-slate-700 lg:hidden" @click="sidebarOpen = true">&#9776;</button>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-black text-navy-900 dark:text-white">{{ __('messages.welcome_name', ['name' => $customerAccount?->name]) }}</p>
                            <p class="text-xs font-semibold text-slate-500">{{ __('messages.dashboard.description') }}</p>
                        </div>
                        <x-customer-language-switcher class="hidden md:block" />
                        <x-pwa-install-button class="hidden h-10 w-10 items-center justify-center rounded-xl bg-build-orange text-white sm:inline-flex" />
                        <button class="rounded-xl border border-slate-200 px-3 py-2 text-sm font-bold dark:border-slate-700" @click="darkMode = window.hardexTheme?.toggle() === 'dark'" x-text="darkMode ? @js(__('messages.theme.light')) : @js(__('messages.theme.dark'))"></button>
                        <div class="relative">
                            <button type="button" class="relative grid h-10 w-10 place-items-center rounded-xl border border-slate-200 text-slate-700 dark:border-slate-700 dark:text-slate-200" @click="notificationsOpen = !notificationsOpen; profileOpen = false" aria-label="{{ __('messages.nav.notifications') }}">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" />
                                    <path d="M13.73 21a2 2 0 0 1-3.46 0" />
                                </svg>
                                @if ($unreadNotifications > 0)
                                    <span class="absolute -right-1 -top-1 grid min-h-5 min-w-5 place-items-center rounded-full bg-build-orange px-1 text-[10px] font-black text-white">{{ $unreadNotifications > 99 ? '99+' : $unreadNotifications }}</span>
                                @endif
                            </button>
                            <div x-cloak x-show="notificationsOpen" x-transition @click.outside="notificationsOpen = false" class="absolute right-0 mt-3 w-80 max-w-[calc(100vw-2rem)] rounded-xl border border-slate-200 bg-white p-3 shadow-2xl dark:border-slate-700 dark:bg-navy-900">
                                <div class="mb-2 flex items-center justify-between">
                                    <p class="text-sm font-black">{{ __('messages.nav.notifications') }}</p>
                                    <a href="{{ route('customer.notifications.index') }}" wire:navigate class="text-xs font-bold text-build-orange" @click="notificationsOpen = false">{{ __('messages.notifications.view_all') }}</a>
                                </div>
                                <div class="max-h-80 space-y-2 overflow-y-auto">
                                    @forelse ($recentNotifications as $notification)
                                        <a href="{{ route('customer.notifications.index') }}" wire:navigate @click="notificationsOpen = false" class="block rounded-lg border border-slate-100 p-3 text-sm hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-white/5">
                                            <div class="flex items-center gap-2">
                                                @if (! $notification->read_at)
                                                    <span class="h-2 w-2 rounded-full bg-build-orange"></span>
                                                @endif
                                                <p class="line-clamp-1 font-bold">{{ $notification->title }}</p>
                                            </div>
                                            <p class="mt-1 line-clamp-2 text-xs text-slate-500">{{ $notification->message }}</p>
                                        </a>
                                    @empty
                                        <p class="py-6 text-center text-sm text-slate-500">{{ __('messages.notifications.empty') }}</p>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                        <div class="relative">
                            <button class="flex items-center gap-2 rounded-xl border border-slate-200 p-1.5 pr-3 dark:border-slate-700" @click="profileOpen = !profileOpen; notificationsOpen = false">
                                <img class="h-8 w-8 rounded-lg" src="https://ui-avatars.com/api/?name={{ urlencode($customerAccount?->name ?? 'Customer') }}&background=0d2e50&color=fff" alt="">
                                <span class="hidden text-sm font-bold sm:block">{{ $customerAccount?->name }}</span>
                            </button>
                            <div x-cloak x-show="profileOpen" x-transition @click.outside="profileOpen = false" class="absolute right-0 mt-3 w-56 rounded-xl border border-slate-200 bg-white p-2 shadow-soft dark:border-slate-700 dark:bg-navy-900">
                                <div class="px-2 py-1 md:hidden"><x-customer-language-switcher /></div>
                                <a class="block rounded-lg px-3 py-2 text-sm hover:bg-slate-100 dark:hover:bg-white/5" href="{{ route('customer.profile') }}" wire:navigate>{{ __('messages.nav.profile') }}</a>
                                <form method="POST" action="{{ route('customer.logout') }}">
                                    @csrf
                                    <button class="block w-full rounded-lg px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10">{{ __('messages.nav.logout') }}</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </header>

                <main class="min-h-[calc(100vh-8rem)] px-4 py-6 sm:px-6">
                    <x-flash />
                    {{ $slot }}
                </main>

                <footer class="border-t border-slate-200 px-4 py-4 text-xs font-semibold text-slate-500 dark:border-slate-800 sm:px-6">
                    © {{ now()->year }} {{ $companyName }} {{ __('messages.customer_portal') }}. {{ __('messages.powered_by') }}
                </footer>
            </div>
        </div>

        @livewireScripts
    </body>
</html>
