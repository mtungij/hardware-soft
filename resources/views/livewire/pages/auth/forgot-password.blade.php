<?php

use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

use function Livewire\Volt\layout;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.guest');

state(['email' => '']);

rules(['email' => ['required', 'string', 'email']]);

$sendPasswordResetLink = function () {
    $this->validate();

    try {
        $status = Password::sendResetLink($this->only('email'));
    } catch (\Throwable $exception) {
        Log::error('Password reset email delivery failed.', [
            'exception' => $exception::class,
            'email_hash' => hash('sha256', str($this->email)->trim()->lower()->toString()),
            'mailer' => config('mail.default'),
        ]);

        $this->addError('email', __('password_reset.request.failure'));

        return;
    }

    if ($status != Password::RESET_LINK_SENT) {
        $this->addError('email', __($status));

        return;
    }

    $this->reset('email');

    Session::flash('status', __('password_reset.request.success'));
};

?>

<div>
    <div class="text-center">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('password_reset.request.title') }}</h1>
        <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-400">
            {{ __('password_reset.request.description') }}
        </p>
    </div>

    <x-auth-session-status class="mt-5" :status="session('status')" />

    <form wire:submit="sendPasswordResetLink" class="mt-6 space-y-5">
        <div>
            <x-input-label for="email" :value="__('password_reset.request.email')" />
            <x-text-input wire:model="email" id="email" class="block mt-1 w-full" type="email" name="email" required autofocus />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div>
            <x-primary-button class="w-full justify-center bg-orange-600 hover:bg-orange-700 focus:bg-orange-700 active:bg-orange-800 focus:ring-orange-500" wire:loading.attr="disabled" wire:target="sendPasswordResetLink">
                <span wire:loading.remove wire:target="sendPasswordResetLink">{{ __('password_reset.request.submit') }}</span>
                <span wire:loading wire:target="sendPasswordResetLink">{{ __('password_reset.request.sending') }}</span>
            </x-primary-button>
        </div>

        <div class="text-center">
            <a href="{{ route('login') }}" wire:navigate class="text-sm font-medium text-orange-600 hover:text-orange-700 dark:text-orange-400 dark:hover:text-orange-300">
                {{ __('password_reset.request.back') }}
            </a>
        </div>
    </form>
</div>
