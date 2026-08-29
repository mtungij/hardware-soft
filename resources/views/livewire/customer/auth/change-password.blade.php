<?php

use App\Services\CustomerPortalCredentialService;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Session;

use function Livewire\Volt\layout;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.auth');

state(['current_password' => '', 'password' => '', 'password_confirmation' => '']);

rules([
    'current_password' => ['required', 'string'],
    'password' => ['required', 'confirmed', Password::defaults()],
]);

$changePassword = function (CustomerPortalCredentialService $credentials) {
    $this->validate();
    $credentials->changePassword(auth('customer')->user(), $this->current_password, $this->password);
    Session::regenerate();
    session()->flash('success', 'Password changed successfully.');
    $this->redirectRoute('customer.dashboard', navigate: true);
};

?>

<div class="flex min-h-screen items-center justify-center bg-slate-100 p-6 dark:bg-slate-950">
    <x-card title="Change Your Temporary Password" class="w-full max-w-lg">
        <p class="mb-6 text-sm text-slate-500">For your security, choose a new password before continuing to the Customer Portal.</p>
        <form wire:submit="changePassword" class="space-y-4">
            <x-form-input label="Current / Temporary Password" name="current_password" wire:model="current_password" type="password" required autofocus />
            <x-form-input label="New Password" name="password" wire:model="password" type="password" required />
            <x-form-input label="Confirm New Password" name="password_confirmation" wire:model="password_confirmation" type="password" required />
            <button class="w-full rounded-xl bg-build-orange px-4 py-3 text-sm font-black text-white">Change Password &amp; Continue</button>
        </form>
    </x-card>
</div>
