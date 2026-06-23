<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
         <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Hardex POS') }}</title>

        @php
            $themeColor = '#06b6d4';
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
    <body class="overflow-x-hidden font-sans antialiased">
        @php
            $navIcons = [
                'dashboard' => ['M3 13h8V3H3v10Z', 'M13 21h8V11h-8v10Z', 'M13 3v6h8V3h-8Z', 'M3 21h8v-6H3v6Z'],
                'inventory' => ['M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z', 'M3.27 6.96 12 12.01l8.73-5.05', 'M12 22.08V12'],
                'purchases' => ['M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4H6Z', 'M3 6h18', 'M16 10a4 4 0 0 1-8 0'],
                'warehouse' => ['M3 21V9l9-6 9 6v12', 'M9 21v-8h6v8', 'M3 9h18'],
                'sales' => ['M4 19.5A2.5 2.5 0 0 1 6.5 17H20', 'M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z', 'M8 7h8', 'M8 11h6'],
                'accounting' => ['M4 3h16v18H4z', 'M8 7h8', 'M8 11h8', 'M8 15h3', 'M15 15h1'],
                'reports' => ['M4 19V5', 'M4 19h16', 'M8 16V9', 'M12 16V7', 'M16 16v-5'],
                'admin' => ['M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z', 'M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.65 1.65 0 0 0 15 19.4a1.65 1.65 0 0 0-1 .6l-.09.09a2 2 0 1 1-3.82 0L10 20a1.65 1.65 0 0 0-1-.6 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-.6-1l-.09-.09a2 2 0 1 1 0-3.82L4 10a1.65 1.65 0 0 0 .6-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-.6l.09-.09a2 2 0 1 1 3.82 0L14 4a1.65 1.65 0 0 0 1 .6 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c0 .38.13.74.36 1.03l.24.3a2 2 0 1 1 0 3.34l-.24.3c-.23.29-.36.65-.36 1.03Z'],
                'product' => ['M20 7 12 3 4 7l8 4 8-4Z', 'M4 7v10l8 4 8-4V7', 'M12 11v10'],
                'list' => ['M8 6h13', 'M8 12h13', 'M8 18h13', 'M3 6h.01', 'M3 12h.01', 'M3 18h.01'],
                'ruler' => ['M4 19 19 4', 'M7 16l-2-2', 'M10 13l-2-2', 'M13 10l-2-2', 'M16 7l-2-2'],
                'summary' => ['M3 3v18h18', 'M7 16l3-3 3 2 5-7'],
                'truck' => ['M10 17h4V5H2v12h3', 'M14 17h1', 'M19 17h3v-6l-3-4h-5', 'M7 17a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z', 'M19 17a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z'],
                'supplier' => ['M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2', 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z', 'M22 21v-2a4 4 0 0 0-3-3.87', 'M16 3.13a4 4 0 0 1 0 7.75'],
                'stock' => ['M4 21V8l8-5 8 5v13', 'M8 21v-7h8v7'],
                'transfer' => ['M7 7h11l-3-3', 'M18 7l-3 3', 'M17 17H6l3 3', 'M6 17l3-3'],
                'adjust' => ['M12 20v-6', 'M12 10V4', 'M6 20v-4', 'M6 12V4', 'M18 20v-8', 'M18 8V4', 'M4 16h4', 'M10 10h4', 'M16 12h4'],
                'pos' => ['M4 4h16v12H4z', 'M8 20h8', 'M12 16v4'],
                'customer' => ['M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2', 'M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z'],
                'money' => ['M3 6h18v12H3z', 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z', 'M6 9h.01', 'M18 15h.01'],
                'receipt' => ['M6 2h12v20l-3-2-3 2-3-2-3 2V2Z', 'M9 7h6', 'M9 11h6', 'M9 15h4'],
                'bank' => ['M3 10h18', 'M5 10v8', 'M9 10v8', 'M15 10v8', 'M19 10v8', 'M4 18h16', 'M12 3 3 8h18l-9-5Z'],
                'chart' => ['M3 3v18h18', 'M8 17V9', 'M13 17V5', 'M18 17v-6'],
                'users' => ['M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2', 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z', 'M23 21v-2a4 4 0 0 0-3-3.87'],
                'roles' => ['M12 3 4 7v6c0 5 3.4 9 8 10 4.6-1 8-5 8-10V7l-8-4Z', 'M9 12l2 2 4-4'],
                'branch' => ['M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18', 'M4 22h16', 'M9 6h1', 'M14 6h1', 'M9 10h1', 'M14 10h1', 'M9 14h1', 'M14 14h1'],
                'settings' => ['M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z', 'M19 12h2', 'M3 12h2', 'M12 3v2', 'M12 19v2', 'M17 7l1.4-1.4', 'M5.6 18.4 7 17', 'M17 17l1.4 1.4', 'M5.6 5.6 7 7'],
                'mail' => ['M4 4h16v16H4z', 'M4 7l8 6 8-6'],
            ];
            $navigationGroups = [
                ['label' => 'Dashboard', 'icon' => 'dashboard', 'items' => [['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'dashboard', 'roles' => []]]],
                ['label' => 'Inventory', 'icon' => 'inventory', 'items' => [
                    ['label' => 'Products', 'route' => 'products.index', 'icon' => 'product', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Store Keeper', 'Cashier', 'Accountant']],
                    ['label' => 'Categories', 'route' => 'categories.index', 'icon' => 'list', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Store Keeper', 'Cashier', 'Accountant']],
                    ['label' => 'Units', 'route' => 'units.index', 'icon' => 'ruler', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Store Keeper', 'Cashier', 'Accountant']],
                    ['label' => 'Inventory Summary', 'route' => 'inventory-summary.index', 'icon' => 'summary', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Store Keeper', 'Cashier', 'Accountant']],
                ]],
                ['label' => 'Purchases', 'icon' => 'purchases', 'items' => [
                    ['label' => 'Purchases', 'route' => 'purchases.index', 'icon' => 'purchases', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Store Keeper', 'Accountant']],
                    ['label' => 'Suppliers', 'route' => 'suppliers.index', 'icon' => 'supplier', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Store Keeper', 'Cashier', 'Accountant']],
                ]],
                ['label' => 'Warehouse', 'icon' => 'warehouse', 'items' => [
                    ['label' => 'Store Stock', 'route' => 'store-stock.index', 'icon' => 'stock', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Store Keeper', 'Cashier']],
                    ['label' => 'Dispensing Stock', 'route' => 'dispensing-stock.index', 'icon' => 'stock', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Store Keeper', 'Cashier']],
                    ['label' => 'Stock Transfers', 'route' => 'stock-transfers.index', 'icon' => 'transfer', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Store Keeper', 'Accountant']],
                    ['label' => 'Stock Movements', 'route' => 'stock-movements.index', 'icon' => 'truck', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Store Keeper', 'Accountant']],
                    ['label' => 'Stock Adjustments', 'route' => 'stock-adjustments.index', 'icon' => 'adjust', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Store Keeper']],
                ]],
                ['label' => 'Sales', 'icon' => 'sales', 'items' => [
                    ['label' => 'POS', 'route' => 'pos.index', 'icon' => 'pos', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Cashier']],
                    ['label' => 'Sales', 'route' => 'sales.index', 'icon' => 'sales', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Cashier', 'Store Keeper', 'Accountant']],
                    ['label' => 'Customers', 'route' => 'customers.index', 'icon' => 'customer', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Store Keeper', 'Cashier', 'Accountant']],
                    ['label' => 'Credit Sales', 'route' => 'customer-balances.index', 'icon' => 'money', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant', 'Cashier']],
                ]],
                ['label' => 'Accounting', 'icon' => 'accounting', 'items' => [
                    ['label' => 'Expenses', 'route' => 'expenses.index', 'icon' => 'receipt', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                    ['label' => 'Customer Payments', 'route' => 'customer-payments.create', 'icon' => 'money', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant', 'Cashier']],
                    ['label' => 'Customer Receipts', 'route' => 'admin.customer-receipts.index', 'icon' => 'receipt', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                    ['label' => 'Customer Deposits', 'route' => 'admin.customer-deposits.index', 'icon' => 'bank', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                    ['label' => 'Supplier Payments', 'route' => 'supplier-payments.create', 'icon' => 'money', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                    ['label' => 'Supplier Balances', 'route' => 'supplier-balances.index', 'icon' => 'chart', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                    ['label' => 'Cashbook', 'route' => 'cashbook.index', 'icon' => 'accounting', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant', 'Cashier']],
                ]],
                ['label' => 'Customer Communications', 'icon' => 'mail', 'items' => [
                    ['label' => 'Announcements', 'route' => 'admin.announcements.index', 'icon' => 'mail', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                    ['label' => 'Customer Messages', 'route' => 'admin.customer-messages.index', 'icon' => 'mail', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                    ['label' => 'Message Templates', 'route' => 'admin.message-templates.index', 'icon' => 'receipt', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                    ['label' => 'Sent Messages', 'route' => 'admin.sent-messages.index', 'icon' => 'mail', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                ]],
                ['label' => 'Reports', 'icon' => 'reports', 'items' => [
                    ['label' => 'Sales Reports', 'route' => 'reports.sales', 'icon' => 'chart', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                    ['label' => 'Purchase Reports', 'route' => 'reports.purchases', 'icon' => 'reports', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                    ['label' => 'Profit Reports', 'route' => 'reports.profit-loss', 'icon' => 'chart', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                    ['label' => 'Stock Reports', 'route' => 'reports.stock-valuation', 'icon' => 'summary', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                ]],
                ['label' => 'Administration', 'icon' => 'admin', 'items' => [
                    ['label' => 'Users', 'route' => 'users.index', 'icon' => 'users', 'roles' => ['Super Admin', 'Admin']],
                    ['label' => 'Roles', 'route' => 'roles.index', 'icon' => 'roles', 'roles' => ['Super Admin', 'Admin']],
                    ['label' => 'Branches', 'route' => 'branches.index', 'icon' => 'branch', 'roles' => ['Super Admin', 'Admin', 'Manager']],
                    ['label' => 'Customer Accounts', 'route' => 'admin.customer-accounts.index', 'icon' => 'customer', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                    ['label' => 'Customer Notifications', 'route' => 'admin.customer-notifications.index', 'icon' => 'mail', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                    ['label' => 'Settings', 'route' => 'settings.index', 'icon' => 'settings', 'roles' => ['Super Admin', 'Admin']],
                    ['label' => 'Company Settings', 'route' => 'settings.company', 'icon' => 'branch', 'roles' => ['Super Admin', 'Admin']],
                    ['label' => 'Email Settings', 'route' => 'email-settings.index', 'icon' => 'mail', 'roles' => ['Super Admin', 'Admin', 'Manager']],
                    ['label' => 'Email Logs', 'route' => 'purchase-email-logs.index', 'icon' => 'receipt', 'roles' => ['Super Admin', 'Admin', 'Manager']],
                ]],
            ];
            $user = auth()->user();
            $company = \App\Models\Company::current();
            $companySettings = null;

            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('settings')) {
                    $companySettings = \App\Models\Setting::query()->first();
                }
            } catch (\Throwable) {
                $companySettings = null;
            }

            $companyName = $companySettings?->company_name ?: ($company?->company_name ?: config('app.name', 'Hardex POS'));
            $companyLogo = $companySettings?->company_logo ?: $company?->logo;
            $companyInitials = collect(preg_split('/\s+/', trim($companyName)))
                ->filter()
                ->map(fn ($word) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($word, 0, 1)))
                ->take(2)
                ->join('') ?: 'HP';
            $currentStaffLocale = session('staff_locale', app()->getLocale());
        @endphp

        <div
            x-data="{
                sidebarOpen: false,
                profileOpen: false,
                collapsed: false,
                darkMode: window.hardexTheme?.get() === 'dark',
                toggleTheme() {
                    this.darkMode = window.hardexTheme?.toggle() === 'dark';
                }
            }"
            x-init="window.addEventListener('hardex-theme-changed', event => darkMode = event.detail.theme === 'dark')"
            class="min-h-screen bg-slate-100 text-slate-900 transition-colors duration-300 dark:bg-slate-950 dark:text-slate-100"
        >
            @auth
                <div class="hidden">
                    <livewire:layout.navigation />
                </div>
            @endauth

            <div class="fixed inset-0 z-40 bg-slate-950/50 lg:hidden" x-cloak x-show="sidebarOpen" x-transition.opacity @click="sidebarOpen = false"></div>

            <aside class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-slate-200 bg-white shadow-xl shadow-slate-900/5 transition-all duration-300 dark:border-slate-800 dark:bg-slate-900 lg:translate-x-0" :class="{ '-translate-x-full': !sidebarOpen, 'translate-x-0': sidebarOpen, 'lg:w-20': collapsed, 'lg:w-72': !collapsed }" @mouseenter="collapsed = false">
                <div class="flex h-14 items-center gap-2 border-b border-slate-200 px-3 dark:border-slate-800">
                    <div class="grid h-10 w-10 place-items-center overflow-hidden rounded-xl bg-build-orange text-base font-bold text-white shadow-lg shadow-orange-500/30">
                        <img data-brand-logo src="{{ $companyLogo ? asset('storage/'.$companyLogo) : '' }}" class="{{ $companyLogo ? 'block' : 'hidden' }} h-full w-full object-contain bg-white p-1.5" alt="{{ $companyName }} logo">
                        <span data-brand-initials class="{{ $companyLogo ? 'hidden' : 'block' }}">{{ $companyInitials }}</span>
                    </div>
                    <div x-show="!collapsed" x-transition.opacity>
                        <p data-brand-name class="max-w-44 truncate text-sm font-black uppercase tracking-wide text-navy-900 dark:text-white">{{ $companyName }}</p>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Business workspace</p>
                    </div>
                    <button type="button" class="ml-auto hidden h-8 w-8 place-items-center rounded-lg border border-slate-200 text-slate-600 transition hover:border-build-orange hover:text-build-orange dark:border-slate-700 dark:text-slate-300 lg:grid" @click="collapsed = !collapsed" aria-label="Toggle sidebar">
                        <svg class="h-4 w-4 transition-transform" :class="{ 'rotate-180': collapsed }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M15 18l-6-6 6-6" />
                        </svg>
                    </button>
                </div>

                <nav class="flex-1 overflow-y-auto px-2 py-3">
                    @foreach ($navigationGroups as $group)
                        @php
                            $visibleItems = collect($group['items'])->filter(fn ($item) => blank($item['roles']) || $user?->hasAnyRole($item['roles']));
                        @endphp
                        @if ($visibleItems->isNotEmpty())
                            <div class="mb-2" x-data="{ open: true }">
                                <button type="button" class="group flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left text-xs font-semibold uppercase text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-white/5 dark:hover:text-white" @click="open = !open" :aria-expanded="open">
                                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-500 transition group-hover:bg-white group-hover:text-build-orange dark:bg-white/5 dark:text-slate-300 dark:group-hover:bg-white/10">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            @foreach ($navIcons[$group['icon']] ?? $navIcons['dashboard'] as $path)
                                                <path d="{{ $path }}" />
                                            @endforeach
                                        </svg>
                                    </span>
                                    <span x-show="!collapsed" x-transition.opacity class="truncate tracking-wide">{{ $group['label'] }}</span>
                                    <svg x-show="!collapsed" class="ml-auto h-4 w-4 transition-transform" :class="{ 'rotate-180': open }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M6 9l6 6 6-6" />
                                    </svg>
                                </button>
                                <div x-show="open || collapsed" class="mt-1 space-y-1">
                                    @foreach ($visibleItems as $item)
                                        @php
                                            $activePattern = \Illuminate\Support\Str::endsWith($item['route'], '.index')
                                                ? \Illuminate\Support\Str::beforeLast($item['route'], '.').'.*'
                                                : null;
                                            $isActive = request()->routeIs($item['route']) || ($activePattern && request()->routeIs($activePattern));
                                            $iconName = $item['icon'] ?? $group['icon'];
                                        @endphp
                                        <a href="{{ route($item['route']) }}" wire:navigate @click="sidebarOpen = false" class="group relative flex items-center gap-2 rounded-lg px-2.5 py-2 text-sm font-semibold transition focus:outline-none focus:ring-4 focus:ring-orange-500/20 {{ $isActive ? 'bg-orange-50 text-build-orange shadow-sm dark:bg-orange-500/15 dark:text-orange-300' : 'text-slate-700 hover:bg-slate-100 hover:text-navy-900 dark:text-slate-200 dark:hover:bg-white/5 dark:hover:text-white' }}">
                                            @if ($isActive)
                                                <span class="absolute left-0 top-1/2 h-7 w-1 -translate-y-1/2 rounded-r-full bg-build-orange"></span>
                                            @endif
                                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg transition {{ $isActive ? 'bg-build-orange text-white shadow-lg shadow-orange-500/25' : 'bg-slate-100 text-slate-500 group-hover:bg-white group-hover:text-build-orange dark:bg-white/5 dark:text-slate-300 dark:group-hover:bg-white/10' }}">
                                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                    @foreach ($navIcons[$iconName] ?? $navIcons['dashboard'] as $path)
                                                        <path d="{{ $path }}" />
                                                    @endforeach
                                                </svg>
                                            </span>
                                            <span x-show="!collapsed" x-transition.opacity class="truncate">{{ $item['label'] }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                </nav>

                <div class="border-t border-slate-200 p-3 dark:border-slate-800">
                    <div class="rounded-xl bg-slate-950 p-3 text-white dark:bg-white/5" x-show="!collapsed" x-transition.opacity>
                        <p class="text-sm font-bold">{{ $user?->branch?->name ?? 'Main Branch' }}</p>
                        <p class="mt-1 text-xs text-slate-300">{{ $user?->roles->pluck('name')->join(', ') ?: 'No role assigned' }}</p>
                    </div>
                </div>
            </aside>

            <div class="min-w-0 overflow-x-hidden transition-all duration-300" :class="{ 'lg:pl-20': collapsed, 'lg:pl-72': !collapsed }">
                <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/90 backdrop-blur dark:border-slate-800 dark:bg-slate-950/90">
                    <div class="flex h-16 min-w-0 items-center gap-2 px-3 sm:gap-3 sm:px-6">
                        <button class="grid h-10 w-10 place-items-center rounded-lg border border-slate-200 text-slate-600 dark:border-slate-700 dark:text-slate-300 lg:hidden" @click="sidebarOpen = true">
                            <span class="text-xl">&#9776;</span>
                        </button>

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $companyName }}</p>
                            <p class="hidden text-xs text-slate-500 dark:text-slate-400 sm:block">Staff ERP workspace</p>
                        </div>

                        <x-pwa-install-button class="hidden h-10 w-10 items-center justify-center rounded-lg bg-build-orange text-white shadow-lg shadow-orange-500/25 sm:inline-flex" />
                        <div class="hs-dropdown relative inline-flex">
                            <button type="button" class="hs-dropdown-toggle hidden rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-build-orange dark:border-slate-700 dark:text-slate-200 sm:inline-flex">
                                {{ $currentStaffLocale === 'sw' ? '🇹🇿 Kiswahili' : '🇬🇧 English' }}
                            </button>
                            <div class="hs-dropdown-menu z-50 mt-2 hidden min-w-44 rounded-xl border border-slate-200 bg-white p-2 shadow-lg dark:border-slate-700 dark:bg-slate-900" role="menu">
                                @foreach (['en' => '🇬🇧 English', 'sw' => '🇹🇿 Kiswahili'] as $locale => $label)
                                    <form method="POST" action="{{ route('staff.language', $locale) }}">
                                        @csrf
                                        <button type="submit" class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium {{ $currentStaffLocale === $locale ? 'bg-orange-50 text-build-orange dark:bg-orange-500/15' : 'text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-white/5' }}" onclick="localStorage.setItem('hardex_staff_locale', '{{ $locale }}')">{{ $label }}</button>
                                    </form>
                                @endforeach
                            </div>
                        </div>
                        <button class="hidden rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold transition hover:border-build-orange dark:border-slate-700 sm:inline-flex" :class="darkMode ? 'bg-slate-900 text-white dark:bg-slate-800' : 'bg-white text-slate-700'" @click="toggleTheme()" aria-label="Toggle theme">
                            <span x-text="darkMode ? 'Dark active' : 'Light active'"></span>
                        </button>
                        <button class="relative grid h-10 w-10 place-items-center rounded-lg border border-slate-200 text-slate-600 dark:border-slate-700 dark:text-slate-300" aria-label="Notifications">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" />
                                <path d="M13.73 21a2 2 0 0 1-3.46 0" />
                            </svg>
                            <span class="absolute right-2 top-2 h-2.5 w-2.5 rounded-full bg-build-orange ring-2 ring-white dark:ring-navy-950"></span>
                        </button>

                        <div class="relative">
                            <button class="flex items-center gap-2 rounded-xl border border-slate-200 p-1.5 pr-3 dark:border-slate-700" @click="profileOpen = !profileOpen">
                                <img class="h-8 w-8 rounded-lg object-cover" src="{{ $user?->profile_photo ? asset('storage/'.$user->profile_photo) : 'https://ui-avatars.com/api/?name='.urlencode($user?->name ?? 'Admin').'&background=0d2e50&color=fff' }}" alt="{{ $user?->name ?? 'User' }}">
                                <span class="hidden text-sm font-bold sm:block">{{ $user?->name ?? 'User' }}</span>
                            </button>
                            <div x-cloak x-show="profileOpen" x-transition @click.outside="profileOpen = false" class="absolute right-0 mt-3 w-64 rounded-xl border border-slate-200 bg-white p-2 shadow-soft dark:border-slate-700 dark:bg-navy-900">
                                <div class="border-b border-slate-100 px-3 py-3 dark:border-slate-800">
                                    <p class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $user?->name ?? 'User' }}</p>
                                    <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $user?->email ?? '' }}</p>
                                </div>
                                <a class="mt-2 block rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:hover:bg-white/5" href="{{ route('profile') }}" wire:navigate>Profile</a>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10">Logout</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </header>

                <main class="min-w-0 overflow-x-hidden px-3 py-5 sm:px-6 sm:py-6 2xl:px-8">
                    <x-flash />
                    {{ $slot }}
                </main>
                <footer class="border-t border-slate-200 px-4 py-4 text-xs font-semibold text-slate-500 dark:border-slate-800 sm:px-6">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <span data-brand-name>{{ $companyName }}</span>
                        <span>Enterprise inventory, POS, accounting, and reporting workspace.</span>
                    </div>
                </footer>
            </div>
        </div>

        @livewireScripts
        <script>
            document.addEventListener('livewire:init', () => {
                Livewire.on('buildmart-theme-color-updated', (event) => {
                    const color = Array.isArray(event) ? event[0]?.color : event?.color;

                    if (/^#[0-9A-Fa-f]{6}$/.test(color || '')) {
                        document.documentElement.style.setProperty('--build-theme', color);
                        window.dispatchEvent(new CustomEvent('buildmart-theme-changed'));
                    }
                });

                Livewire.on('hardex-brand-updated', (event) => {
                    const payload = Array.isArray(event) ? event[0] : event;
                    const name = payload?.name || 'Hardex POS';
                    const initials = payload?.initials || 'HP';
                    const logoUrl = payload?.logoUrl || '';

                    document.querySelectorAll('[data-brand-name]').forEach((element) => {
                        element.textContent = name;
                    });

                    document.querySelectorAll('[data-brand-initials]').forEach((element) => {
                        element.textContent = initials;
                        element.classList.toggle('hidden', Boolean(logoUrl));
                        element.classList.toggle('block', ! logoUrl);
                    });

                    document.querySelectorAll('[data-brand-logo]').forEach((element) => {
                        element.src = logoUrl;
                        element.alt = `${name} logo`;
                        element.classList.toggle('hidden', ! logoUrl);
                        element.classList.toggle('block', Boolean(logoUrl));
                    });
                });
            });
        </script>
    </body>
</html>
