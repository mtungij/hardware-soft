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
        throw ValidationException::withMessages(['email' => __('messages.auth.invalid_credentials')]);
    }

    $account = Auth::guard('customer')->user();

    if ($account->isSuspended()) {
        Auth::guard('customer')->logout();
        request()->session()->regenerateToken();
        throw ValidationException::withMessages(['email' => __('messages.auth.suspended')]);
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
                    <p class="text-2xl font-black">{{ __('messages.hardex_customer_portal') }}</p>
                    <p class="text-sm text-slate-300">{{ __('messages.welcome_message') }}</p>
                </div>
            </div>
            <div class="mt-12 grid gap-4 sm:grid-cols-2">
                @foreach ([__('messages.auth.features.debts'), __('messages.auth.features.receipts'), __('messages.auth.features.deposits'), __('messages.auth.features.statements')] as $feature)
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                        <p class="text-lg font-black text-build-orange">✓</p>
                        <p class="mt-2 font-bold">{{ $feature }}</p>
                    </div>
                @endforeach
            </div>
        </div>
        <p class="text-sm text-slate-300">{{ __('messages.support.need_help') }} WhatsApp +255629364847</p>
    </section>

    <section class="flex items-center justify-center p-6">
        <div class="w-full max-w-md">
            <div class="mb-8 text-center lg:hidden">
                <img src="{{ asset('images/hardex.png') }}" class="mx-auto h-16 w-16 rounded-2xl bg-white object-contain p-2 shadow-soft" alt="Hardex">
            </div>
            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-soft dark:border-slate-800 dark:bg-slate-900 sm:p-8">
                <div class="mb-4 flex justify-end">
                    <x-pwa-install-button />
                </div>
                <h1 class="text-2xl font-black text-navy-900 dark:text-white">{{ __('messages.auth.login') }}</h1>
                <p class="mt-1 text-sm font-semibold text-slate-500">{{ __('messages.auth.login_intro') }}</p>

                <form wire:submit="login" class="mt-6 space-y-4">
                    <x-form-input :label="__('messages.auth.email')" name="email" wire:model="email" type="email" required autofocus />
                    <x-form-input :label="__('messages.auth.password')" name="password" wire:model="password" type="password" required />
                    <label class="flex items-center gap-2 text-sm font-semibold text-slate-600 dark:text-slate-300">
                        <input wire:model="remember" type="checkbox" class="rounded border-slate-300 text-build-orange focus:ring-build-orange">
                        {{ __('messages.auth.remember_me') }}
                    </label>
                    <button class="w-full rounded-xl bg-build-orange px-4 py-3 text-sm font-black text-white shadow-lg shadow-orange-500/25" wire:loading.attr="disabled">
                        <span wire:loading.remove>{{ __('messages.auth.login_button') }}</span><span wire:loading>{{ __('messages.auth.signing_in') }}</span>
                    </button>
                </form>

                <div class="mt-6 text-center text-sm font-semibold text-slate-500">
                    {{ __('messages.auth.no_account') }}
                    <a href="{{ route('customer.register') }}" wire:navigate class="font-black text-build-orange">{{ __('messages.auth.create_account') }}</a>
                </div>
                <a href="https://wa.me/255629364847" target="_blank" class="mt-4 block rounded-xl bg-emerald-50 px-4 py-3 text-center text-sm font-black text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200">{{ __('messages.support.chat_whatsapp') }}</a>
            </div>
        </div>
    </section>
</div>
