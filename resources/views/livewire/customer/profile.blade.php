<?php

use Illuminate\Validation\Rules\Password;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.customer');

state(['name' => '', 'phone' => '', 'email' => '', 'preferred_locale' => 'sw', 'password' => '', 'password_confirmation' => '']);

rules(fn () => [
    'name' => ['required', 'string', 'max:255'],
    'phone' => ['nullable', 'string', 'max:30'],
    'email' => ['required', 'email', 'max:255', 'unique:customer_accounts,email,'.auth('customer')->id()],
    'preferred_locale' => ['required', 'in:sw,en'],
    'password' => ['nullable', 'confirmed', Password::defaults()],
]);

mount(function () {
    $account = auth('customer')->user();
    $this->name = $account->name;
    $this->phone = $account->phone;
    $this->email = $account->email;
    $this->preferred_locale = $account->preferred_locale ?: app()->getLocale();
});

$save = function () {
    $data = $this->validate();
    $account = auth('customer')->user();
    $account->fill([
        'name' => $data['name'],
        'phone' => $data['phone'],
        'email' => $data['email'],
        'preferred_locale' => $data['preferred_locale'],
    ]);

    if ($data['password']) {
        $account->password = $data['password'];
    }

    $account->save();
    $account->customer()->update([
        'phone' => $data['phone'],
        'email' => $data['email'],
    ]);

    $this->password = '';
    $this->password_confirmation = '';
    session()->put('customer_locale', $data['preferred_locale']);
    app()->setLocale($data['preferred_locale']);
    session()->flash('success', __('messages.profile.updated'));
};

?>

<div>
    <x-page-header :title="__('messages.profile.title')" :description="__('messages.profile.description')" :breadcrumbs="[__('messages.customer_portal') => route('customer.dashboard'), __('messages.profile.title') => null]">
        <x-pwa-install-button />
    </x-page-header>
    <x-card class="max-w-2xl">
        <form wire:submit="save" class="space-y-4">
            <x-form-input :label="__('messages.auth.full_name')" name="name" wire:model="name" required />
            <x-form-input :label="__('messages.auth.phone')" name="phone" wire:model="phone" />
            <x-form-input :label="__('messages.auth.email')" name="email" wire:model="email" type="email" required />
            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">
                {{ __('messages.profile.language_preference') }}
                <select wire:model="preferred_locale" class="mt-1 block min-h-10 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm outline-none ring-build-orange/20 transition focus:border-build-orange focus:ring-4 dark:border-slate-700 dark:bg-slate-950 dark:text-white">
                    <option value="sw">{{ __('messages.kiswahili') }}</option>
                    <option value="en">{{ __('messages.english') }}</option>
                </select>
                @error('preferred_locale') <span class="mt-1 block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
            </label>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-form-input :label="__('messages.profile.new_password')" name="password" wire:model="password" type="password" />
                <x-form-input :label="__('messages.auth.confirm_password')" name="password_confirmation" wire:model="password_confirmation" type="password" />
            </div>
            <button class="rounded-xl bg-build-orange px-4 py-3 text-sm font-black text-white">{{ __('messages.profile.save') }}</button>
        </form>
    </x-card>
</div>
