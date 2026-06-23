<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Spatie\Permission\Models\Role;

use function Livewire\Volt\layout;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.auth');

state([
    'name' => '',
    'phone' => '',
    'business_name' => '',
    'email' => '',
    'password' => '',
    'password_confirmation' => '',
    'branch_name' => '',
    'terms' => false,
]);

rules([
    'name' => ['required', 'string', 'max:255'],
    'phone' => ['required', 'string', 'max:30'],
    'business_name' => ['required', 'string', 'max:255'],
    'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
    'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
    'branch_name' => ['required', 'string', 'max:255'],
    'terms' => ['accepted'],
]);

$register = function () {
    $validated = $this->validate();

    $user = DB::transaction(function () use ($validated) {
        $baseCode = Str::upper(Str::slug(Str::limit($validated['branch_name'], 10, ''), ''));
        $baseCode = $baseCode ?: 'HDX';
        $code = $baseCode;
        $counter = 1;

        while (Branch::query()->where('code', $code)->exists()) {
            $code = $baseCode.'-'.$counter++;
        }

        $branch = Branch::create([
            'name' => $validated['branch_name'],
            'code' => $code,
            'phone' => $validated['phone'],
            'email' => $validated['email'],
            'address' => $validated['business_name'],
            'status' => 'active',
        ]);

        $user = User::create([
            'branch_id' => $branch->id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'status' => 'active',
            'password' => Hash::make($validated['password']),
        ]);

        if (Role::query()->where('name', 'Admin')->exists()) {
            $user->assignRole('Admin');
        }

        return $user;
    });

    event(new Registered($user));

    Auth::login($user);

    session()->flash('success', 'Hardex account created successfully.');

    $this->redirect(route('dashboard', absolute: false), navigate: true);
};

?>

<div
    class="min-h-screen bg-slate-100 text-slate-900 dark:bg-slate-950 dark:text-slate-100"
    x-data="{
        showPassword: false,
        showConfirmPassword: false,
        tipIndex: 0,
        tips: [
            'Monitor stock levels in real time.',
            'Transfer stock between Store and Dispensing Area.',
            'Track customer balances and supplier debts.',
            'Generate professional business reports instantly.',
            'Manage multiple hardware branches from one system.'
        ],
        darkMode: localStorage.theme ? localStorage.theme === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches,
        toggleTheme() {
            this.darkMode = ! this.darkMode;
            localStorage.theme = this.darkMode ? 'dark' : 'light';
            document.documentElement.classList.toggle('dark', this.darkMode);
        }
    }"
    x-init="document.documentElement.classList.toggle('dark', darkMode); setInterval(() => tipIndex = (tipIndex + 1) % tips.length, 4200)"
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
                    <p class="text-sm font-black uppercase tracking-wide text-orange-300">Launch your hardware ERP</p>
                    <h2 class="mt-4 text-4xl font-black leading-tight xl:text-5xl">Create a secure workspace for your hardware business.</h2>
                    <p class="mt-5 max-w-xl text-base leading-7 text-slate-300">
                        Start managing products, purchases, warehouse stock, POS sales, branch operations, and business reports from one responsive platform.
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
            <div class="w-full max-w-xl">
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
                    <div>
                        <h2 class="text-2xl font-black text-navy-900 dark:text-white">Create Account</h2>
                        <p class="mt-1 text-sm font-medium text-slate-500 dark:text-slate-400">Set up your Hardex hardware business workspace</p>
                    </div>

                    <form wire:submit="register" class="mt-6 grid gap-4 sm:grid-cols-2">
                        <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                            Full Name
                            <input wire:model="name" type="text" required autofocus autocomplete="name" class="mt-1 block min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none ring-build-orange/20 transition focus:border-build-orange focus:ring-4 dark:border-slate-700 dark:bg-slate-950 dark:text-white">
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </label>

                        <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                            Phone Number
                            <input wire:model="phone" type="tel" required autocomplete="tel" class="mt-1 block min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none ring-build-orange/20 transition focus:border-build-orange focus:ring-4 dark:border-slate-700 dark:bg-slate-950 dark:text-white">
                            <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                        </label>

                        <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                            Business Name
                            <input wire:model="business_name" type="text" required class="mt-1 block min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none ring-build-orange/20 transition focus:border-build-orange focus:ring-4 dark:border-slate-700 dark:bg-slate-950 dark:text-white">
                            <x-input-error :messages="$errors->get('business_name')" class="mt-2" />
                        </label>

                        <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                            Email Address
                            <input wire:model="email" type="email" required autocomplete="username" class="mt-1 block min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none ring-build-orange/20 transition focus:border-build-orange focus:ring-4 dark:border-slate-700 dark:bg-slate-950 dark:text-white">
                            <x-input-error :messages="$errors->get('email')" class="mt-2" />
                        </label>

                        <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                            Password
                            <div class="relative mt-1">
                                <input wire:model="password" :type="showPassword ? 'text' : 'password'" required autocomplete="new-password" class="block min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 pr-20 text-sm outline-none ring-build-orange/20 transition focus:border-build-orange focus:ring-4 dark:border-slate-700 dark:bg-slate-950 dark:text-white">
                                <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg px-2 py-1 text-xs font-black text-build-orange" @click="showPassword = !showPassword" x-text="showPassword ? 'Hide' : 'Show'"></button>
                            </div>
                            <x-input-error :messages="$errors->get('password')" class="mt-2" />
                        </label>

                        <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                            Confirm Password
                            <div class="relative mt-1">
                                <input wire:model="password_confirmation" :type="showConfirmPassword ? 'text' : 'password'" required autocomplete="new-password" class="block min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 pr-20 text-sm outline-none ring-build-orange/20 transition focus:border-build-orange focus:ring-4 dark:border-slate-700 dark:bg-slate-950 dark:text-white">
                                <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg px-2 py-1 text-xs font-black text-build-orange" @click="showConfirmPassword = !showConfirmPassword" x-text="showConfirmPassword ? 'Hide' : 'Show'"></button>
                            </div>
                            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
                        </label>

                        <label class="block text-sm font-bold text-slate-700 dark:text-slate-200 sm:col-span-2">
                            Branch Name
                            <input wire:model="branch_name" type="text" required class="mt-1 block min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none ring-build-orange/20 transition focus:border-build-orange focus:ring-4 dark:border-slate-700 dark:bg-slate-950 dark:text-white">
                            <x-input-error :messages="$errors->get('branch_name')" class="mt-2" />
                        </label>

                        <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-3 text-sm font-semibold text-slate-600 dark:border-slate-800 dark:text-slate-300 sm:col-span-2">
                            <input wire:model="terms" type="checkbox" class="mt-0.5 rounded border-slate-300 text-build-orange focus:ring-build-orange dark:border-slate-700 dark:bg-slate-950">
                            <span>I agree to Terms & Conditions</span>
                        </label>
                        <x-input-error :messages="$errors->get('terms')" class="sm:col-span-2" />

                        <div class="flex flex-col gap-3 sm:col-span-2 sm:flex-row">
                            <button class="flex min-h-11 flex-1 items-center justify-center rounded-xl bg-build-orange px-4 py-3 text-sm font-black text-white shadow-lg shadow-orange-500/25" wire:loading.attr="disabled">
                                <span wire:loading.remove>Create Account</span>
                                <span wire:loading>Creating account...</span>
                            </button>
                            <a href="{{ route('login') }}" wire:navigate class="flex min-h-11 flex-1 items-center justify-center rounded-xl border border-slate-200 px-4 py-3 text-sm font-black dark:border-slate-700">
                                Back to Login
                            </a>
                        </div>
                    </form>

                    <div class="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-950">
                        <p class="text-sm font-black text-navy-900 dark:text-white">Need Help?</p>
                        <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="text-sm text-slate-500 dark:text-slate-400">
                                <p class="font-bold text-slate-700 dark:text-slate-200">WhatsApp Support</p>
                                <p>Available: Monday – Sunday</p>
                            </div>
                            <a href="https://wa.me/255629364847" target="_blank" rel="noopener" class="inline-flex items-center justify-center gap-2 rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">
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
