<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

use function Livewire\Volt\layout;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.auth');

state(['email' => '', 'password' => '', 'remember' => false]);

rules([
    'email' => ['required', 'email'],
    'password' => ['required', 'string'],
]);

$login = function () {
    $credentials = $this->validate();
    $remember = (bool) $this->remember;
    unset($credentials['remember']);

    if (! Auth::guard('customer')->attempt($credentials, $remember)) {
        throw ValidationException::withMessages(['email' => 'These credentials do not match our customer records.']);
    }

    $account = Auth::guard('customer')->user();

    if ($account->isSuspended()) {
        Auth::guard('customer')->logout();
        request()->session()->regenerateToken();
        throw ValidationException::withMessages(['email' => 'Your customer portal account is suspended. Please contact support.']);
    }

    request()->session()->regenerate();
    $account->forceFill(['last_login_at' => now()])->save();

    $this->redirectRoute($account->isPending() ? 'customer.pending' : 'customer.dashboard', navigate: true);
};

?>

<div class="grid min-h-screen bg-slate-100 dark:bg-slate-950 lg:grid-cols-2">
    <section class="hidden bg-navy-900 p-10 text-white lg:flex lg:flex-col lg:justify-between">
        <div>
            <div class="flex items-center gap-3">
                <img src="{{ asset('images/hardex.png') }}" class="h-14 w-14 rounded-2xl bg-white object-contain p-2" alt="Hardex">
                <div>
                    <p class="text-2xl font-black">Hardex Customer Portal</p>
                    <p class="text-sm text-slate-300">Track debts, receipts, deposits, and statements.</p>
                </div>
            </div>
            <div class="mt-12 grid gap-4 sm:grid-cols-2">
                @foreach (['Debt tracking', 'Receipt uploads', 'Deposit balances', 'Customer statements'] as $feature)
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                        <p class="text-lg font-black text-build-orange">✓</p>
                        <p class="mt-2 font-bold">{{ $feature }}</p>
                    </div>
                @endforeach
            </div>
        </div>
        <p class="text-sm text-slate-300">Need help? WhatsApp +255629364847</p>
    </section>

    <section class="flex items-center justify-center p-6">
        <div class="w-full max-w-md">
            <div class="mb-8 text-center lg:hidden">
                <img src="{{ asset('images/hardex.png') }}" class="mx-auto h-16 w-16 rounded-2xl bg-white object-contain p-2 shadow-soft" alt="Hardex">
            </div>
            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-soft dark:border-slate-800 dark:bg-slate-900 sm:p-8">
                <h1 class="text-2xl font-black text-navy-900 dark:text-white">Customer Login</h1>
                <p class="mt-1 text-sm font-semibold text-slate-500">Sign in to view your Hardex account.</p>

                <form wire:submit="login" class="mt-6 space-y-4">
                    <x-form-input label="Email Address" name="email" wire:model="email" type="email" required autofocus />
                    <x-form-input label="Password" name="password" wire:model="password" type="password" required />
                    <label class="flex items-center gap-2 text-sm font-semibold text-slate-600 dark:text-slate-300">
                        <input wire:model="remember" type="checkbox" class="rounded border-slate-300 text-build-orange focus:ring-build-orange">
                        Remember me
                    </label>
                    <button class="w-full rounded-xl bg-build-orange px-4 py-3 text-sm font-black text-white shadow-lg shadow-orange-500/25" wire:loading.attr="disabled">
                        <span wire:loading.remove>Login</span><span wire:loading>Signing in...</span>
                    </button>
                </form>

                <div class="mt-6 text-center text-sm font-semibold text-slate-500">
                    No customer account?
                    <a href="{{ route('customer.register') }}" wire:navigate class="font-black text-build-orange">Create one</a>
                </div>
                <a href="https://wa.me/255629364847" target="_blank" class="mt-4 block rounded-xl bg-emerald-50 px-4 py-3 text-center text-sm font-black text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200">WhatsApp Support</a>
            </div>
        </div>
    </section>
</div>
