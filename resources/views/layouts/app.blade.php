<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
         <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
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

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800,900&display=swap" rel="stylesheet" />
        <x-pwa-head />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans antialiased">
        @php
            $navigationGroups = [
                ['label' => 'Dashboard', 'icon' => 'DB', 'items' => [['label' => 'Dashboard', 'route' => 'dashboard', 'roles' => []]]],
                ['label' => 'Inventory', 'icon' => 'IN', 'items' => [
                    ['label' => 'Products', 'route' => 'products.index', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Store Keeper', 'Cashier', 'Accountant']],
                    ['label' => 'Categories', 'route' => 'categories.index', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Store Keeper', 'Cashier', 'Accountant']],
                    ['label' => 'Units', 'route' => 'units.index', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Store Keeper', 'Cashier', 'Accountant']],
                    ['label' => 'Inventory Summary', 'route' => 'inventory-summary.index', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Store Keeper', 'Cashier', 'Accountant']],
                ]],
                ['label' => 'Purchases', 'icon' => 'PU', 'items' => [
                    ['label' => 'Purchases', 'route' => 'purchases.index', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Store Keeper', 'Accountant']],
                    ['label' => 'Suppliers', 'route' => 'suppliers.index', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Store Keeper', 'Cashier', 'Accountant']],
                ]],
                ['label' => 'Warehouse', 'icon' => 'WH', 'items' => [
                    ['label' => 'Store Stock', 'route' => 'store-stock.index', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Store Keeper', 'Cashier']],
                    ['label' => 'Dispensing Stock', 'route' => 'dispensing-stock.index', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Store Keeper', 'Cashier']],
                    ['label' => 'Stock Transfers', 'route' => 'stock-transfers.index', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Store Keeper', 'Accountant']],
                    ['label' => 'Stock Movements', 'route' => 'stock-movements.index', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Store Keeper', 'Accountant']],
                    ['label' => 'Stock Adjustments', 'route' => 'stock-adjustments.index', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Store Keeper']],
                ]],
                ['label' => 'Sales', 'icon' => 'SA', 'items' => [
                    ['label' => 'POS', 'route' => 'pos.index', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Cashier']],
                    ['label' => 'Sales', 'route' => 'sales.index', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Cashier', 'Store Keeper', 'Accountant']],
                    ['label' => 'Customers', 'route' => 'customers.index', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Store Keeper', 'Cashier', 'Accountant']],
                    ['label' => 'Credit Sales', 'route' => 'customer-balances.index', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant', 'Cashier']],
                ]],
                ['label' => 'Accounting', 'icon' => 'AC', 'items' => [
                    ['label' => 'Expenses', 'route' => 'expenses.index', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                    ['label' => 'Customer Payments', 'route' => 'customer-payments.create', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant', 'Cashier']],
                    ['label' => 'Customer Receipts', 'route' => 'admin.customer-receipts.index', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                    ['label' => 'Customer Deposits', 'route' => 'admin.customer-deposits.index', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                    ['label' => 'Supplier Payments', 'route' => 'supplier-payments.create', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                    ['label' => 'Supplier Balances', 'route' => 'supplier-balances.index', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                    ['label' => 'Cashbook', 'route' => 'cashbook.index', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant', 'Cashier']],
                ]],
                ['label' => 'Reports', 'icon' => 'RP', 'items' => [
                    ['label' => 'Sales Reports', 'route' => 'reports.sales', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                    ['label' => 'Purchase Reports', 'route' => 'reports.purchases', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                    ['label' => 'Profit Reports', 'route' => 'reports.profit-loss', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                    ['label' => 'Stock Reports', 'route' => 'reports.stock-valuation', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                ]],
                ['label' => 'Administration', 'icon' => 'AD', 'items' => [
                    ['label' => 'Users', 'route' => 'users.index', 'roles' => ['Super Admin', 'Admin']],
                    ['label' => 'Roles', 'route' => 'roles.index', 'roles' => ['Super Admin', 'Admin']],
                    ['label' => 'Branches', 'route' => 'branches.index', 'roles' => ['Super Admin', 'Admin', 'Manager']],
                    ['label' => 'Customer Accounts', 'route' => 'admin.customer-accounts.index', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                    ['label' => 'Customer Notifications', 'route' => 'admin.customer-notifications.index', 'roles' => ['Super Admin', 'Admin', 'Manager', 'Accountant']],
                    ['label' => 'Settings', 'route' => 'settings.index', 'roles' => ['Super Admin', 'Admin']],
                    ['label' => 'Company Settings', 'route' => 'settings.company', 'roles' => ['Super Admin', 'Admin']],
                    ['label' => 'Email Settings', 'route' => 'email-settings.index', 'roles' => ['Super Admin', 'Admin', 'Manager']],
                    ['label' => 'Email Logs', 'route' => 'purchase-email-logs.index', 'roles' => ['Super Admin', 'Admin', 'Manager']],
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

            $companyName = $company?->company_name ?: ($companySettings?->company_name ?: config('app.name', 'Hardex POS'));
            $companyLogo = $company?->logo ?: $companySettings?->company_logo;
            $companyInitials = collect(preg_split('/\s+/', trim($companyName)))
                ->filter()
                ->map(fn ($word) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($word, 0, 1)))
                ->take(2)
                ->join('') ?: 'HP';
        @endphp

        <div
            x-data="{
                sidebarOpen: false,
                profileOpen: false,
                collapsed: false,
                darkMode: localStorage.theme ? localStorage.theme === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches,
                toggleTheme() {
                    this.darkMode = ! this.darkMode;
                    localStorage.theme = this.darkMode ? 'dark' : 'light';
                    document.documentElement.classList.toggle('dark', this.darkMode);
                    window.dispatchEvent(new CustomEvent('buildmart-theme-changed'));
                }
            }"
            x-init="document.documentElement.classList.toggle('dark', darkMode); $watch('darkMode', value => document.documentElement.classList.toggle('dark', value))"
            class="min-h-screen bg-slate-100 text-slate-900 transition-colors duration-300 dark:bg-slate-950 dark:text-slate-100"
        >
            @auth
                <div class="hidden">
                    <livewire:layout.navigation />
                </div>
            @endauth

            <div class="fixed inset-0 z-40 bg-slate-950/50 lg:hidden" x-cloak x-show="sidebarOpen" x-transition.opacity @click="sidebarOpen = false"></div>

            <aside class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-slate-200 bg-white transition-all duration-300 dark:border-slate-800 dark:bg-slate-900 lg:translate-x-0" :class="{ '-translate-x-full': !sidebarOpen, 'translate-x-0': sidebarOpen, 'lg:w-20': collapsed, 'lg:w-72': !collapsed }" @mouseenter="collapsed = false">
                <div class="flex h-16 items-center gap-3 border-b border-slate-200 px-4 dark:border-slate-800">
                    <div class="grid h-11 w-11 place-items-center overflow-hidden rounded-xl bg-build-orange text-lg font-black text-white shadow-lg shadow-orange-500/30">
                        <img data-brand-logo src="{{ $companyLogo ? asset('storage/'.$companyLogo) : '' }}" class="{{ $companyLogo ? 'block' : 'hidden' }} h-full w-full object-contain bg-white p-1.5" alt="{{ $companyName }} logo">
                        <span data-brand-initials class="{{ $companyLogo ? 'hidden' : 'block' }}">{{ $companyInitials }}</span>
                    </div>
                    <div x-show="!collapsed" x-transition.opacity>
                        <p data-brand-name class="max-w-44 truncate text-sm font-black uppercase tracking-wide text-navy-900 dark:text-white">{{ $companyName }}</p>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Business workspace</p>
                    </div>
                    <button type="button" class="ml-auto hidden h-9 w-9 place-items-center rounded-lg border border-slate-200 text-sm font-black dark:border-slate-700 lg:grid" @click="collapsed = !collapsed" aria-label="Toggle sidebar">&lt;</button>
                </div>

                <nav class="flex-1 overflow-y-auto px-3 py-4">
                    @foreach ($navigationGroups as $group)
                        @php
                            $visibleItems = collect($group['items'])->filter(fn ($item) => blank($item['roles']) || $user?->hasAnyRole($item['roles']));
                        @endphp
                        @if ($visibleItems->isNotEmpty())
                            <div class="mb-4" x-data="{ open: true }">
                                <button type="button" class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-xs font-black uppercase tracking-wide text-slate-400 transition hover:bg-slate-100 dark:hover:bg-white/5" @click="open = !open" :aria-expanded="open">
                                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-slate-100 text-[11px] text-slate-500 dark:bg-white/5 dark:text-slate-300">{{ $group['icon'] }}</span>
                                    <span x-show="!collapsed" x-transition.opacity>{{ $group['label'] }}</span>
                                    <span x-show="!collapsed" class="ml-auto transition-transform" :class="{ 'rotate-180': open }">v</span>
                                </button>
                                <div x-show="open || collapsed" class="mt-1 space-y-1">
                                    @foreach ($visibleItems as $item)
                                        @php
                                            $routeGroup = \Illuminate\Support\Str::before($item['route'], '.');
                                            $isActive = request()->routeIs($item['route']) || request()->routeIs($routeGroup.'.*');
                                        @endphp
                                        <a href="{{ route($item['route']) }}" wire:navigate @click="sidebarOpen = false" class="group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-semibold transition focus:outline-none focus:ring-4 focus:ring-orange-500/20 {{ $isActive ? 'bg-orange-50 text-build-orange dark:bg-orange-500/15 dark:text-orange-300' : 'text-slate-600 hover:bg-slate-100 hover:text-navy-900 dark:text-slate-300 dark:hover:bg-white/5 dark:hover:text-white' }}">
                                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-xs font-black {{ $isActive ? 'bg-build-orange text-white' : 'bg-slate-100 text-slate-500 dark:bg-white/5 dark:text-slate-400' }}">{{ collect(explode(' ', $item['label']))->map(fn ($word) => $word[0])->take(2)->join('') }}</span>
                                            <span x-show="!collapsed" x-transition.opacity>{{ $item['label'] }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                </nav>

                <div class="border-t border-slate-200 p-4 dark:border-slate-800">
                    <div class="rounded-xl bg-navy-900 p-4 text-white dark:bg-white/5" x-show="!collapsed" x-transition.opacity>
                        <p class="text-sm font-bold">{{ $user?->branch?->name ?? 'Main Branch' }}</p>
                        <p class="mt-1 text-xs text-slate-300">{{ $user?->roles->pluck('name')->join(', ') ?: 'No role assigned' }}</p>
                    </div>
                </div>
            </aside>

            <div class="transition-all duration-300" :class="{ 'lg:pl-20': collapsed, 'lg:pl-72': !collapsed }">
                <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/90 backdrop-blur dark:border-slate-800 dark:bg-slate-950/90">
                    <div class="flex h-16 items-center gap-3 px-4 sm:px-6">
                        <button class="grid h-10 w-10 place-items-center rounded-lg border border-slate-200 text-slate-600 dark:border-slate-700 dark:text-slate-300 lg:hidden" @click="sidebarOpen = true">
                            <span class="text-xl">&#9776;</span>
                        </button>

                        <div class="min-w-0 flex-1">
                            <div class="relative max-w-2xl">
                                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">&#8981;</span>
                                <input class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-4 text-sm outline-none ring-build-orange/20 placeholder:text-slate-400 focus:border-build-orange focus:ring-4 dark:border-slate-700 dark:bg-white/5 dark:text-white" placeholder="Search products, categories, suppliers, customers, users...">
                            </div>
                        </div>

                        <x-pwa-install-button class="hidden rounded-xl bg-build-orange px-3 py-2 text-sm font-black text-white shadow-lg shadow-orange-500/25" />
                        <button class="hidden rounded-xl border border-slate-200 px-3 py-2 text-sm font-bold transition hover:border-build-orange dark:border-slate-700 sm:inline-flex" @click="toggleTheme()" x-text="darkMode ? 'Light' : 'Dark'" aria-label="Toggle theme"></button>
                        <button class="relative grid h-10 w-10 place-items-center rounded-lg border border-slate-200 text-xs font-bold dark:border-slate-700" aria-label="Notifications">
                            <span>Bell</span>
                            <span class="absolute right-2 top-2 h-2.5 w-2.5 rounded-full bg-build-orange ring-2 ring-white dark:ring-navy-950"></span>
                        </button>

                        <div class="relative">
                            <button class="flex items-center gap-2 rounded-xl border border-slate-200 p-1.5 pr-3 dark:border-slate-700" @click="profileOpen = !profileOpen">
                                <img class="h-8 w-8 rounded-lg object-cover" src="{{ $user?->profile_photo ? asset('storage/'.$user->profile_photo) : 'https://ui-avatars.com/api/?name='.urlencode($user?->name ?? 'Admin').'&background=0d2e50&color=fff' }}" alt="{{ $user?->name ?? 'User' }}">
                                <span class="hidden text-sm font-bold sm:block">{{ $user?->name ?? 'User' }}</span>
                            </button>
                            <div x-cloak x-show="profileOpen" x-transition @click.outside="profileOpen = false" class="absolute right-0 mt-3 w-56 rounded-xl border border-slate-200 bg-white p-2 shadow-soft dark:border-slate-700 dark:bg-navy-900">
                                <a class="block rounded-lg px-3 py-2 text-sm hover:bg-slate-100 dark:hover:bg-white/5" href="{{ route('profile') }}" wire:navigate>Profile</a>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button class="block w-full rounded-lg px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10">Logout</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </header>

                <main class="min-h-[calc(100vh-8rem)] px-4 py-6 sm:px-6 2xl:px-8">
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
