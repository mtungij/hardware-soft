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
    'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
]);

$changePassword = function (CustomerPortalCredentialService $credentials) {
    $this->validate();
    $credentials->changePassword(auth('customer')->user(), $this->current_password, $this->password);
    Session::regenerate();
    session()->flash('success', __('application.password_changed_successfully_f7271564'));
    $this->redirectRoute('customer.dashboard', navigate: true);
};

?>

<div class="flex min-h-screen items-center justify-center bg-slate-100 p-6 dark:bg-slate-950">
    <x-card title="{{ __('application.change_your_temporary_password_b23f70fb') }}" class="w-full max-w-lg">
        <p class="mb-6 text-sm text-slate-500">{{ __('application.for_your_security_choose_a_new_password_before_continuing_to_the_customer_p_6cfe1a43') }}</p>
        <form wire:submit="changePassword" class="space-y-4">
            <x-form-input label="{{ __('portal.current_pin') }}" name="current_password" wire:model="current_password" type="password" required autofocus />
            <x-form-input label="{{ __('application.new_password_4894cb39') }}" name="password" wire:model="password" type="password" required />
            <x-form-input label="{{ __('application.confirm_new_password_68dcd71c') }}" name="password_confirmation" wire:model="password_confirmation" type="password" required />
            <button class="w-full rounded-xl bg-build-orange px-4 py-3 text-sm font-black text-white">{{ __('application.change_password_amp_continue_c1e21c17') }}</button>
        </form>
    </x-card>
</div>
