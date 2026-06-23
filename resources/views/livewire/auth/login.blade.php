<?php

use App\Livewire\Forms\LoginForm;
use App\Models\Company;
use App\Models\Setting;
use Illuminate\Support\Facades\Session;

use function Livewire\Volt\form;
use function Livewire\Volt\layout;

layout('layouts.auth');

form(LoginForm::class);

$systemInitialized = function () {
    try {
        if (! \Illuminate\Support\Facades\Schema::hasTable('settings') || ! \Illuminate\Support\Facades\Schema::hasColumn('settings', 'system_initialized')) {
            return true;
        }

        return (bool) Setting::query()->value('system_initialized');
    } catch (\Throwable) {
        return true;
    }
};

$company = fn () => Company::current();

$supportWhatsapp = function () {
    $company = $this->company();

    return $company?->whatsapp_number ?: '255629364847';
};

$supportWhatsappLink = function () {
    $number = preg_replace('/\D+/', '', $this->supportWhatsapp());

    return $number ? "https://wa.me/{$number}" : '#';
};

$login = function () {
    $this->validate();

    $this->form->authenticate();

    Session::regenerate();

    session()->flash('success', 'Welcome back to Hardex.');

    $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
};

?>

<div
    class="min-h-screen bg-slate-100 text-slate-900 dark:bg-slate-950 dark:text-slate-100"
    x-data="{
        showPassword: false,
        tipIndex: 0,
        tips: [
            'Monitor stock levels in real time.',
            'Transfer stock between Store and Dispensing Area.',
            'Track customer balances and supplier debts.',
            'Generate professional business reports instantly.',
            'Manage multiple hardware branches from one system.'
        ],
        darkMode: window.hardexTheme?.get() === 'dark',
        toggleTheme() {
            this.darkMode = window.hardexTheme?.toggle() === 'dark';
        }
    }"
    x-init="window.addEventListener('hardex-theme-changed', event => darkMode = event.detail.theme === 'dark'); setInterval(() => tipIndex = (tipIndex + 1) % tips.length, 4200)"
>
    <div class="grid min-h-screen lg:grid-cols-[1.08fr_.92fr]">
        <section class="relative hidden overflow-hidden bg-navy-900 p-8 text-white dark:bg-slate-900 lg:flex lg:flex-col lg:justify-between xl:p-12">
            <div class="absolute inset-0 opacity-10" style="background-image: linear-gradient(135deg, #ffffff 1px, transparent 1px); background-size: 28px 28px;"></div>
            <div class="relative">
                <div class="flex items-center gap-4">
                    <div class="grid h-16 w-16 place-items-center overflow-hidden rounded-2xl bg-white p-2 shadow-soft">
                        <img src="{{ asset('images/hardex.png') }}" alt="Hardex logo" class="h-full w-full object-contain">
                    </div>
                    <div>
                        <h1 class="text-2xl font-black tracking-tight">Hardex Hardware ERP</h1>
                        <p class="text-sm font-semibold text-slate-300">Smart Hardware Store Management Solution</p>
                    </div>
                </div>

                <div class="mt-12 max-w-2xl">
                    <p class="text-sm font-black uppercase tracking-wide text-orange-300">Premium hardware business workspace</p>
                    <h2 class="mt-4 text-4xl font-black leading-tight xl:text-5xl">Manage inventory, POS, warehouse, and accounts from one clean system.</h2>
                    <p class="mt-5 max-w-xl text-base leading-7 text-slate-300">
                        Run products, purchases, suppliers, customers, inventory, warehouse, stock transfers, POS sales, expenses, and reports with a fast modern ERP interface.
                    </p>
                </div>

                <div class="mt-10 grid gap-3 sm:grid-cols-2">
                    @foreach (['Inventory Management', 'Purchase Management', 'Warehouse Control', 'POS Sales', 'Customer Management', 'Supplier Management', 'Financial Reports', 'Multi-Branch Support'] as $feature)
                        <div class="rounded-xl border border-white/10 bg-white/5 p-3 backdrop-blur">
                            <span class="text-build-orange">✓</span>
                            <span class="ml-2 text-sm font-bold">{{ $feature }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="relative rounded-2xl border border-white/10 bg-white/5 p-5 backdrop-blur">
                <p class="text-xs font-black uppercase tracking-wide text-slate-400">System Tips</p>
                <p class="mt-2 text-lg font-bold" x-text="tips[tipIndex]" x-transition></p>
            </div>
        </section>

        <section class="flex min-h-screen items-center justify-center px-4 py-8 sm:px-6 lg:px-10">
            <div class="w-full max-w-md">
                <div class="mb-6 flex items-center justify-between">
                    <div class="flex items-center gap-3 lg:hidden">
                        <div class="grid h-12 w-12 place-items-center overflow-hidden rounded-xl bg-white p-1.5 shadow-soft dark:bg-slate-900">
                            <img src="{{ asset('images/hardex.png') }}" alt="Hardex logo" class="h-full w-full object-contain">
                        </div>
                        <div>
                            <p class="font-black text-navy-900 dark:text-white">Hardex</p>
                            <p class="text-xs font-semibold text-slate-500">Hardware ERP</p>
                        </div>
                    </div>
                    <div class="ml-auto flex items-center gap-2">
                        <x-pwa-install-button class="hidden rounded-lg bg-build-orange px-3 py-2 text-xs font-black text-white shadow-lg shadow-orange-500/25" />
                        <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold dark:border-slate-700" @click="toggleTheme()" x-text="darkMode ? 'Light' : 'Dark'"></button>
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-soft dark:border-slate-800 dark:bg-slate-900 sm:p-8">
                    <x-auth-session-status class="mb-4" :status="session('status')" />

                    @if (session('success'))
                        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
                            {{ session('success') }}
                        </div>
                    @endif

                    <div>
                        <h2 class="text-2xl font-black text-navy-900 dark:text-white">Welcome Back</h2>
                        <p class="mt-1 text-sm font-medium text-slate-500 dark:text-slate-400">Sign in to continue using Hardex</p>
                    </div>

                    <form wire:submit="login" class="mt-6 space-y-4">
                        <label class="block text-sm font-bold text-slate-700 dark:text-slate-200" for="email">
                            Email Address
                            <input wire:model="form.email" id="email" type="email" required autofocus autocomplete="username" class="mt-1 block min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none ring-build-orange/20 transition focus:border-build-orange focus:ring-4 dark:border-slate-700 dark:bg-slate-950 dark:text-white">
                            <x-input-error :messages="$errors->get('form.email')" class="mt-2" />
                        </label>

                        <label class="block text-sm font-bold text-slate-700 dark:text-slate-200" for="password">
                            Password
                            <div class="relative mt-1">
                                <input wire:model="form.password" id="password" :type="showPassword ? 'text' : 'password'" required autocomplete="current-password" class="block min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 pr-20 text-sm outline-none ring-build-orange/20 transition focus:border-build-orange focus:ring-4 dark:border-slate-700 dark:bg-slate-950 dark:text-white">
                                <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg px-2 py-1 text-xs font-black text-build-orange" @click="showPassword = !showPassword" x-text="showPassword ? 'Hide' : 'Show'" aria-label="Show or hide password"></button>
                            </div>
                            <x-input-error :messages="$errors->get('form.password')" class="mt-2" />
                        </label>

                        <div class="flex items-center justify-between gap-3">
                            <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 dark:text-slate-300">
                                <input wire:model="form.remember" type="checkbox" class="rounded border-slate-300 text-build-orange focus:ring-build-orange dark:border-slate-700 dark:bg-slate-950">
                                Remember Me
                            </label>
                            <a href="{{ route('password.request') }}" wire:navigate class="text-sm font-bold text-build-orange">Forgot Password?</a>
                        </div>

                        <button class="flex min-h-11 w-full items-center justify-center rounded-xl bg-build-orange px-4 py-3 text-sm font-black text-white shadow-lg shadow-orange-500/25" wire:loading.attr="disabled">
                            <span wire:loading.remove>Login</span>
                            <span wire:loading>Signing in...</span>
                        </button>
                    </form>

                    <div class="my-6 flex items-center gap-3">
                        <div class="h-px flex-1 bg-slate-200 dark:bg-slate-800"></div>
                        <span class="text-xs font-black text-slate-400">OR</span>
                        <div class="h-px flex-1 bg-slate-200 dark:bg-slate-800"></div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <button type="button" disabled class="cursor-not-allowed rounded-xl border border-slate-200 px-4 py-3 text-sm font-black opacity-60 dark:border-slate-700">Google</button>
                        <button type="button" disabled class="cursor-not-allowed rounded-xl border border-slate-200 px-4 py-3 text-sm font-black opacity-60 dark:border-slate-700">Microsoft</button>
                    </div>

                    <a href="{{ route('setup') }}" wire:navigate class="mt-4 flex min-h-11 w-full items-center justify-center rounded-xl border border-build-orange/40 bg-orange-50 px-4 py-3 text-sm font-black text-navy-900 transition hover:border-build-orange hover:bg-orange-100 dark:border-orange-400/30 dark:bg-orange-500/10 dark:text-white dark:hover:bg-orange-500/15">
                        <span class="mr-2 grid h-6 w-6 place-items-center rounded-lg bg-build-orange text-white">+</span>
                        Create Account
                    </a>
                    <p class="mt-2 text-center text-xs font-semibold text-slate-500 dark:text-slate-400">
                        Open the setup wizard for company, owner, and first branch.
                    </p>

                    <div class="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-950">
                        <p class="text-sm font-black text-navy-900 dark:text-white">Need Help?</p>
                        <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="text-sm text-slate-500 dark:text-slate-400">
                                <p class="font-bold text-slate-700 dark:text-slate-200">WhatsApp Support</p>
                                <p>{{ $this->supportWhatsapp() }}</p>
                            </div>
                            <a href="{{ $this->supportWhatsappLink() }}" target="_blank" rel="noopener" class="inline-flex items-center justify-center gap-2 rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">
                                <span aria-hidden="true">☏</span>
                                Contact Us
                            </a>
                        </div>
                    </div>
                </div>

                <footer class="mt-6 text-center text-xs font-semibold text-slate-500 dark:text-slate-400">
                    © {{ now()->year }} Hardex Hardware ERP · Powered by Hardex · Version 1.0
                </footer>
            </div>
        </section>
    </div>
</div>
