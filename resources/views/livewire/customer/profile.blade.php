<?php

use Illuminate\Validation\Rules\Password;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.customer');

state(['name' => '', 'phone' => '', 'email' => '', 'password' => '', 'password_confirmation' => '']);

rules(fn () => [
    'name' => ['required', 'string', 'max:255'],
    'phone' => ['nullable', 'string', 'max:30'],
    'email' => ['required', 'email', 'max:255', 'unique:customer_accounts,email,'.auth('customer')->id()],
    'password' => ['nullable', 'confirmed', Password::defaults()],
]);

mount(function () {
    $account = auth('customer')->user();
    $this->name = $account->name;
    $this->phone = $account->phone;
    $this->email = $account->email;
});

$save = function () {
    $data = $this->validate();
    $account = auth('customer')->user();
    $account->fill([
        'name' => $data['name'],
        'phone' => $data['phone'],
        'email' => $data['email'],
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
    session()->flash('success', 'Profile updated.');
};

?>

<div>
    <x-page-header title="Profile" description="Manage your customer portal account." :breadcrumbs="['Customer Portal' => route('customer.dashboard'), 'Profile' => null]" />
    <x-card class="max-w-2xl">
        <form wire:submit="save" class="space-y-4">
            <x-form-input label="Full Name" name="name" wire:model="name" required />
            <x-form-input label="Phone" name="phone" wire:model="phone" />
            <x-form-input label="Email" name="email" wire:model="email" type="email" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-form-input label="New Password" name="password" wire:model="password" type="password" />
                <x-form-input label="Confirm Password" name="password_confirmation" wire:model="password_confirmation" type="password" />
            </div>
            <button class="rounded-xl bg-build-orange px-4 py-3 text-sm font-black text-white">Save Profile</button>
        </form>
    </x-card>
</div>
